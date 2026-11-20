<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\CommandRun\CommandRunRecorder;
use App\CommandRun\JobStopped;
use App\Elastic\Indices;
use App\Enum\IssueStatusEnum;
use App\Http\ApiClient;
use App\Http\ApiFailure;
use App\People\User;
use App\People\Users;
use Elastica\Document;

/**
 * Writes Forge issues to the issue index, as the import and the refresh of a single issue do.
 *
 * The requests go through App\Http\ApiClient: retries and failures as for every API, see App\Http\ApiRequests.
 */
final class ForgeIssueImporter
{
    public function __construct(
        private readonly Indices $indices,
        private readonly ApiClient $forge,
        private readonly ForgeIssueList $forgeIssues,
        private readonly Users $users,
        private readonly ProjectIssues $projectIssues,
        private readonly CommandRunRecorder $recorder,
    ) {
    }

    /**
     * @throws ApiFailure the index keeps the issue as it is
     */
    public function refresh(int $id): IssueRefreshResult
    {
        $result = $this->update([$id], refresh: true);
        if ($result['updated'] > 0) {
            return IssueRefreshResult::UPDATED;
        }

        return $result['removed'] > 0 ? IssueRefreshResult::REMOVED : IssueRefreshResult::NOT_FOUND;
    }

    /**
     * Loads the issues from Forge and writes them. Issues that Forge does not list for the index any more are
     * removed: deleted, private, or in another project.
     *
     * @param list<int>                                               $ids
     * @param (\Closure(): void)|null                                 $afterIssue called after each listed issue
     * @param (\Closure(array<string, mixed>, \Exception): void)|null $onFailure  handles an issue that could not be
     *                                                                            written, instead of throwing
     * @param bool                                                    $refresh    the index shows the issues right away
     *
     * @return array{updated: int, removed: int}
     *
     * @throws ApiFailure
     */
    public function update(array $ids, ?\Closure $afterIssue = null, ?\Closure $onFailure = null, bool $refresh = false): array
    {
        $updated = 0;
        $removed = 0;
        foreach (array_chunk($ids, ForgeIssueList::PAGE_SIZE) as $chunk) {
            $issues = $this->forgeIssues->byIds($chunk);
            foreach ($issues as $issue) {
                $updated += $this->tryImport($issue, $onFailure, $refresh);
                if (null !== $afterIssue) {
                    $afterIssue();
                }
            }
            $listed = array_map(static fn (array $issue): int => (int) $issue['id'], $issues);
            $removed += $this->remove(array_values(array_diff($chunk, $listed)));
        }

        return ['updated' => $updated, 'removed' => $removed];
    }

    /**
     * @param list<int> $ids
     *
     * @return int the number of removed issues
     */
    public function remove(array $ids): int
    {
        return [] === $ids ? 0 : $this->projectIssues->remove($ids);
    }

    /**
     * @param array<string, mixed>                                    $issue
     * @param (\Closure(array<string, mixed>, \Exception): void)|null $onFailure
     *
     * @return int 1 if the issue was written
     */
    public function tryImport(array $issue, ?\Closure $onFailure, bool $refresh = false): int
    {
        if (null === $onFailure) {
            $this->import($issue, $refresh);

            return 1;
        }
        try {
            $this->import($issue, $refresh);

            return 1;
        } catch (JobStopped $stopped) {
            throw $stopped;
        } catch (\Exception $e) {
            $onFailure($issue, $e);

            return 0;
        }
    }

    /**
     * Writes an issue as Forge lists it. Issues under review are loaded again with their journals, the import
     * reads the Gerrit changes they link from them.
     *
     * @param array<string, mixed> $issue
     * @param bool                 $refresh whether the index shows the issue right away, for the page that asked
     *
     * @throws ApiFailure Forge could not be asked, the issue keeps its previous state in the index
     * @throws JobStopped
     */
    public function import(array $issue, bool $refresh = false): void
    {
        if (isset($issue['status']['id']) && IssueStatusEnum::UNDER_REVIEW === IssueStatusEnum::tryFrom((int) $issue['status']['id'])) {
            $issue = $this->projectIssues->withoutPrivateNotes($this->issue((int) $issue['id'], 'journals,relations'));
        }

        // The author and the assignee the index "user" lacks, empty: "Update users" fills them
        $this->users->ensure(array_values(array_filter([User::fromIssue($issue['author'] ?? []), User::fromIssue($issue['assigned_to'] ?? [])])));
        $document = self::document($issue);
        $index = $this->indices->issues();
        $index->addDocument(new Document((string) $document['id'], $document));
        if ($refresh) {
            $index->refresh();
        }
        $this->recorder->document();
    }

    /**
     * The document of the issue index for an issue as Forge sends it: the custom fields as fields of their own,
     * the dates in the format of the index. Raise ImportSchema::ISSUE when it takes more or other data.
     *
     * @param array<string, mixed> $issue
     *
     * @return array<string, mixed>
     */
    public static function document(array $issue): array
    {
        foreach ($issue['custom_fields'] ?? [] as $customField) {
            switch ($customField['id'] ?? null) {
                case 3:
                    if (!empty($customField['value'])) {
                        $issue['tags'] = array_map(trim(...), explode(',', (string) $customField['value']));
                    }
                    break;
                case 4:
                    $issue['typo3_version'] = $customField['value'] ?? '-';
                    break;
                case 5:
                    $issue['php_version'] = $customField['value'] ?? '-';
                    break;
                case 8:
                    $issue['complexity'] = $customField['value'] ?? '-';
                    break;
                case 15:
                    // custom field for regression could not be set, empty, 0 or 1.
                    $issue['isregression'] = ($customField['value'] ?? '0') === '1';
                    break;
                case 18:
                    $issue['focus']['name'] = $customField['value'] ?? '-';
                    break;
                default:
            }
        }
        // "duplicates" relations of Forge, from both sides: "issue_id" duplicates "issue_to_id". Kept as the numbers
        // of the other issues, the other relations are not needed
        $issue['duplicates'] = [];
        $issue['duplicated_by'] = [];
        foreach ($issue['relations'] ?? [] as $relation) {
            if ('duplicates' !== ($relation['relation_type'] ?? null)) {
                continue;
            }
            if ((int) ($relation['issue_id'] ?? 0) === (int) $issue['id']) {
                $issue['duplicates'][] = (int) $relation['issue_to_id'];
            } else {
                $issue['duplicated_by'][] = (int) $relation['issue_id'];
            }
        }
        $issue['updated_on'] = self::indexDate((string) $issue['updated_on']);
        $issue['created_on'] = self::indexDate((string) $issue['created_on']);
        unset($issue['start_date'], $issue['due_date'], $issue['relations']);
        $issue[ImportSchema::FIELD] = ImportSchema::ISSUE;

        return $issue;
    }

    /**
     * A date of Forge in the format of the index.
     */
    public static function indexDate(string $date): string
    {
        return new \DateTime($date)->format('Y/m/d H:i:s');
    }

    /**
     * An issue as Forge shows it.
     *
     * @param string $include what Forge adds to the issue, "journals,relations"
     *
     * @return array<string, mixed>
     *
     * @throws ApiFailure
     */
    private function issue(int $id, string $include): array
    {
        $answer = $this->forge->object(sprintf('/issues/%d.json', $id), ['include' => $include], 'the issue');
        if (!isset($answer['issue']) || !is_array($answer['issue'])) {
            throw ApiFailure::unexpected($this->forge->name, 'the issue', json_encode($answer));
        }

        return $answer['issue'];
    }
}
