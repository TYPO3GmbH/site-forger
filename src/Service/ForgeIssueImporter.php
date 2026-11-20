<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

use App\DTO\User;
use App\Elastic\ElasticUtility;
use App\Elastic\ImportSchema;
use App\Elastic\ProjectIssues;
use App\Elastic\Users;
use App\Enum\IssueStatusEnum;
use App\Http\ApiClient;
use App\Http\ApiFailure;
use Elastica\Document;

/**
 * Writes Forge issues to the issue index, as the import and the refresh of a single issue do.
 *
 * The requests go through App\Http\ApiClient: retries and failures as for every API, see App\Http\ApiRequests.
 */
final class ForgeIssueImporter
{
    public function __construct(
        private readonly ApiClient $forge,
        private readonly Users $users,
        private readonly ProjectIssues $projectIssues,
        private readonly CommandRunRecorder $recorder,
    ) {
    }

    /**
     * Loads an issue from Forge again and writes it to the index. An issue that is gone from Forge, private
     * or in another project now is removed from the index.
     *
     * @throws ApiFailure Forge could not be asked, the index keeps the issue as it is
     */
    public function refresh(int $id): IssueRefreshResult
    {
        try {
            $issue = $this->issue($id, 'relations');
        } catch (ApiFailure $e) {
            // Deleted on Forge, or not visible to the import any more
            if ($e->isNotFound()) {
                return $this->projectIssues->remove([$id]) > 0 ? IssueRefreshResult::REMOVED : IssueRefreshResult::NOT_FOUND;
            }
            throw $e;
        }
        if (!$this->projectIssues->belongsToProject($issue)) {
            return $this->projectIssues->remove([$id]) > 0 ? IssueRefreshResult::REMOVED : IssueRefreshResult::NOT_FOUND;
        }
        $this->import($issue, refresh: true);

        return IssueRefreshResult::UPDATED;
    }

    /**
     * Writes an issue as Forge lists it. Issues under review are loaded again with their journals, the import
     * reads the Gerrit changes they link from them.
     *
     * @param array<string, mixed> $issue
     * @param bool                 $refresh whether the index shows the issue right away, for the page that asked
     *
     * @throws ApiFailure Forge could not be asked, the issue keeps its previous state in the index
     */
    public function import(array $issue, bool $refresh = false): void
    {
        if (isset($issue['status']['id']) && IssueStatusEnum::UNDER_REVIEW === IssueStatusEnum::tryFrom((int) $issue['status']['id'])) {
            $issue = $this->projectIssues->withoutPrivateNotes($this->issue((int) $issue['id'], 'journals,relations'));
        }

        // The author and the assignee the index "user" lacks, empty: "Update users" fills them
        $this->users->ensure(array_values(array_filter([User::fromIssue($issue['author'] ?? []), User::fromIssue($issue['assigned_to'] ?? [])])));
        $document = self::document($issue);
        $index = ElasticUtility::getIndexByName('issue');
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
