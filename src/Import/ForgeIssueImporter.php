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
use App\Enum\IssueRelationEnum;
use App\Http\ApiFailure;
use App\People\User;
use App\Repository\UserRepository;
use App\Stream\StreamSchema;
use App\Stream\StreamWriter;
use Elastica\Document;
use Elastica\Exception\NotFoundException;
use Elastica\Exception\ResponseException;

/**
 * Writes Forge issues to the issue index, as the import and the refresh of a single issue do.
 *
 * The requests go through App\Http\ApiClient: retries and failures as for every API, see App\Http\ApiRequests.
 */
final class ForgeIssueImporter
{
    public function __construct(
        private readonly Indices $indices,
        private readonly ForgeIssueApi $forge,
        private readonly ForgeIssueList $forgeIssues,
        private readonly UserRepository $users,
        private readonly ProjectIssues $projectIssues,
        private readonly CommandRunRecorder $recorder,
        private readonly StreamWriter $stream,
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
     * Marks an issue as written by an older import, so the next run loads it again, see ImportSchema::outdated().
     * For an issue the run could not load: the next delta starts after it. An issue the index lacks stays so.
     */
    public function markOutdated(int $id): void
    {
        try {
            $this->indices->issues()->updateDocument(new Document((string) $id, [ImportSchema::FIELD => 0]));
        } catch (NotFoundException|ResponseException) {
            // Not in the index: the comparison with Forge finds it, see IssueReconciliation
        }
    }

    /**
     * Writes an issue as Forge lists it, loaded again with its journals and attachments: a list of Forge has none.
     * The journals are the comments and changes of the issue, the pages show the comments, and the import reads the
     * Gerrit changes they link. The private notes and the files added with them are left out.
     *
     * @param array<string, mixed> $issue
     * @param bool                 $refresh whether the index shows the issue right away, for the page that asked
     *
     * @throws ApiFailure Forge could not be asked, the issue keeps its previous state in the index
     * @throws JobStopped
     */
    public function import(array $issue, bool $refresh = false): void
    {
        $issue = $this->issue((int) $issue['id'], 'journals,relations,attachments');
        // Private or moved since Forge listed it
        if (!$this->projectIssues->belongsToProject($issue)) {
            $this->remove([(int) $issue['id']]);

            return;
        }
        $issue = $this->projectIssues->withoutPrivateNotes($issue);

        // The author and the assignee the index "user" lacks, empty: "Update users" fills them
        $this->users->ensure(array_values(array_filter([User::fromIssue($issue['author'] ?? []), User::fromIssue($issue['assigned_to'] ?? [])])));
        $document = StreamSchema::marked(self::document($issue));
        // The document before its events, see GerritChangeImporter::writeAll()
        $index = $this->indices->issues();
        $index->addDocument(new Document((string) $document['id'], $document));
        $this->stream->issues([$document]);
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
        // The relations of Forge, from both sides: "issue_id" relates to "issue_to_id" as "relation_type" says.
        // The duplicates as the numbers of the other issues, the others with their type seen from this issue.
        $issue['duplicates'] = [];
        $issue['duplicated_by'] = [];
        $issue['issue_relations'] = [];
        foreach ($issue['relations'] ?? [] as $relation) {
            $fromThisIssue = (int) ($relation['issue_id'] ?? 0) === (int) $issue['id'];
            $other = (int) ($fromThisIssue ? ($relation['issue_to_id'] ?? 0) : ($relation['issue_id'] ?? 0));
            $type = (string) ($relation['relation_type'] ?? '');
            if ('duplicates' === $type) {
                $issue[$fromThisIssue ? 'duplicates' : 'duplicated_by'][] = $other;
                continue;
            }
            $relationType = IssueRelationEnum::tryFrom($type);
            if (null !== $relationType && $other > 0) {
                $issue['issue_relations'][] = [
                    'type' => ($fromThisIssue ? $relationType : $relationType->reverse())->value,
                    'issue' => $other,
                ];
            }
        }
        $issue['attachments'] = self::attachments($issue['attachments'] ?? []);
        $issue['updated_on'] = self::indexDate((string) $issue['updated_on']);
        $issue['created_on'] = self::indexDate((string) $issue['created_on']);
        unset($issue['start_date'], $issue['due_date'], $issue['relations']);
        $issue[ImportSchema::FIELD] = ImportSchema::ISSUE;

        return $issue;
    }

    /**
     * The files of the issue, without the thumbnails: their address needs a login on Forge. The dates stay as Forge
     * sends them, like those of the journals.
     *
     * @return list<array{id: int, filename: string, filesize: int, content_type: string, description: string,
     *     content_url: string, author: array<string, mixed>|null, created_on: string}>
     */
    private static function attachments(mixed $attachments): array
    {
        $list = [];
        foreach (is_array($attachments) ? $attachments : [] as $file) {
            if (!is_array($file) || !isset($file['id'], $file['filename'], $file['content_url'])) {
                continue;
            }
            $list[] = [
                'id' => (int) $file['id'],
                'filename' => (string) $file['filename'],
                'filesize' => (int) ($file['filesize'] ?? 0),
                'content_type' => (string) ($file['content_type'] ?? ''),
                'description' => (string) ($file['description'] ?? ''),
                'content_url' => (string) $file['content_url'],
                'author' => is_array($file['author'] ?? null) ? $file['author'] : null,
                'created_on' => (string) ($file['created_on'] ?? ''),
            ];
        }

        return $list;
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
     * @param string $include what Forge adds to the issue, "journals,relations,attachments"
     *
     * @return array<string, mixed>
     *
     * @throws ApiFailure
     */
    private function issue(int $id, string $include): array
    {
        $answer = $this->forge->client()->object(sprintf('/issues/%d.json', $id), ['include' => $include], 'the issue');
        if (!isset($answer['issue']) || !is_array($answer['issue'])) {
            throw ApiFailure::unexpected($this->forge->client()->name, 'the issue', json_encode($answer));
        }

        return $answer['issue'];
    }
}
