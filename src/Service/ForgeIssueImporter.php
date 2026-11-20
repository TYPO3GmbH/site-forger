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
use Elastica\Document;
use Redmine\Api;
use Redmine\Api\AbstractApi;
use Redmine\Api\Issue;
use Redmine\Client\Client;
use Redmine\Exception\UnexpectedResponseException;
use Redmine\Http\HttpClient;
use Redmine\Http\Request;
use Redmine\Http\Response;

/**
 * Writes Forge issues to the issue index, as the import and the refresh of a single issue do.
 *
 * Forge answers a rate limit with 429. Without a handler, see onRateLimit(), that is an error at once: a page
 * waiting for minutes helps nobody. The import waits and tries again.
 */
final class ForgeIssueImporter
{
    /** @var (\Closure(int): void)|null */
    private ?\Closure $rateLimitHandler = null;

    private int $rateLimitRetries = 0;

    public function __construct(
        private readonly Client $forgeClient,
        private readonly Users $users,
        private readonly ProjectIssues $projectIssues,
        private readonly CommandRunRecorder $recorder,
    ) {
    }

    /**
     * Waits for Forge on a rate limit, the handler gets the attempt (1, 2, ...) and sleeps.
     *
     * @param \Closure(int): void $handler
     */
    public function onRateLimit(\Closure $handler, int $retries): void
    {
        $this->rateLimitHandler = $handler;
        $this->rateLimitRetries = $retries;
    }

    /**
     * Sends a request to Forge, waiting and trying again on a rate limit as call() does. An answer with an error
     * status is thrown with the status as code.
     *
     * @throws \RuntimeException
     */
    public function request(Request $request, int $attempt = 0): Response
    {
        if (!$this->forgeClient instanceof HttpClient) {
            throw new \LogicException(sprintf('The client of Forge (%s) sends no requests of its own.', $this->forgeClient::class));
        }
        $response = $this->forgeClient->request($request);
        $status = $response->getStatusCode();
        if (429 === $status && null !== $this->rateLimitHandler && $attempt < $this->rateLimitRetries) {
            ++$attempt;
            ($this->rateLimitHandler)($attempt);

            return $this->request($request, $attempt);
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Forge responded with status %d', $status), $status);
        }

        return $response;
    }

    /**
     * Calls an API of Forge ("issue", "user", ...) with the callback, an answer with an error status is thrown with
     * the status as code. The status comes from the last response of that API.
     *
     * @template T
     *
     * @param \Closure(Api): T $callback
     *
     * @return T
     *
     * @throws \RuntimeException
     */
    public function call(string $api, \Closure $callback, int $attempt = 0): mixed
    {
        $instance = $this->forgeClient->getApi($api);
        try {
            $result = $callback($instance);
            $status = $instance instanceof AbstractApi ? $instance->getLastResponse()->getStatusCode() : 0;
        } catch (UnexpectedResponseException $e) {
            $result = null;
            $status = $e->getResponse()?->getStatusCode() ?? 0;
        }
        if (429 === $status && null !== $this->rateLimitHandler && $attempt < $this->rateLimitRetries) {
            ++$attempt;
            ($this->rateLimitHandler)($attempt);

            return $this->call($api, $callback, $attempt);
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Forge responded with status %d', $status), $status);
        }

        return $result;
    }

    /**
     * Loads an issue from Forge again and writes it to the index. An issue that is gone from Forge, private
     * or in another project now is removed from the index.
     *
     * @throws \RuntimeException Forge could not be asked, the index keeps the issue as it is
     */
    public function refresh(int $id): IssueRefreshResult
    {
        try {
            $answer = $this->call('issue', static fn (Issue $issues): mixed => $issues->show($id, ['include' => ['relations']]));
        } catch (\RuntimeException $e) {
            // Deleted on Forge, or not visible to the import any more
            if (in_array($e->getCode(), [403, 404], true)) {
                return $this->projectIssues->remove([$id]) > 0 ? IssueRefreshResult::REMOVED : IssueRefreshResult::NOT_FOUND;
            }
            throw $e;
        }
        if (!is_array($answer) || !isset($answer['issue']) || !is_array($answer['issue'])) {
            throw new \RuntimeException(sprintf('Forge answered without the issue: %s', mb_substr(is_string($answer) ? trim($answer) : get_debug_type($answer), 0, 200)));
        }
        if (!$this->projectIssues->belongsToProject($answer['issue'])) {
            return $this->projectIssues->remove([$id]) > 0 ? IssueRefreshResult::REMOVED : IssueRefreshResult::NOT_FOUND;
        }
        $this->import($answer['issue'], refresh: true);

        return IssueRefreshResult::UPDATED;
    }

    /**
     * Writes an issue as Forge lists it. Issues under review are loaded again with their journals, the import
     * reads the Gerrit changes they link from them.
     *
     * @param array<string, mixed> $issue
     * @param bool                 $refresh whether the index shows the issue right away, for the page that asked
     *
     * @throws \RuntimeException
     */
    public function import(array $issue, bool $refresh = false): void
    {
        if (isset($issue['status']['id']) && IssueStatusEnum::UNDER_REVIEW === IssueStatusEnum::tryFrom((int) $issue['status']['id'])) {
            $issueWithJournals = $this->call('issue', static fn (Issue $issues): mixed => $issues->show((int) $issue['id'], ['include' => ['journals', 'relations']]));
            if (!is_array($issueWithJournals) || !isset($issueWithJournals['issue'])) {
                // Forge answered without the issue, it keeps its previous state in the index
                throw new \RuntimeException(sprintf('Loading the journals failed, Forge answered: %s', mb_substr(is_string($issueWithJournals) ? trim($issueWithJournals) : get_debug_type($issueWithJournals), 0, 200)));
            }
            $issue = $this->projectIssues->withoutPrivateNotes($issueWithJournals['issue']);
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
}
