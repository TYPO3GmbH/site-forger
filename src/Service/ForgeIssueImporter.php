<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

use App\Elastic\ElasticUtility;
use App\Elastic\ProjectIssues;
use App\Enum\IssueStatusEnum;
use Elastica\Document;
use Redmine\Client\Client;
use Redmine\Exception\UnexpectedResponseException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Writes Forge issues to the issue index, as the import and the refresh of a single issue do.
 *
 * Forge answers a rate limit with 429. Without a handler, see onRateLimit(), that is an error at once: a page
 * waiting for minutes helps nobody. The import waits and tries again.
 */
final class ForgeIssueImporter
{
    /**
     * Seconds the email of a Forge user is kept, so a run does not ask Forge for authors and assignees it knows.
     */
    private const int USER_CACHE_LIFETIME = 86400;

    /** @var array<int|string, string> */
    private array $userCache = [];

    /** @var (\Closure(int): void)|null */
    private ?\Closure $rateLimitHandler = null;

    private int $rateLimitRetries = 0;

    public function __construct(
        private readonly Client $forgeClient,
        private readonly CacheInterface $cache,
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
     * Calls the Forge API, an answer with an error status is thrown with the status as code.
     *
     * @throws \RuntimeException
     */
    public function call(callable $callback, int $attempt = 0): mixed
    {
        try {
            $result = $callback();
            $status = $this->forgeClient->getLastResponseStatusCode();
        } catch (UnexpectedResponseException $e) {
            $result = null;
            $status = $e->getResponse()->getStatusCode();
        }
        if (429 === $status && null !== $this->rateLimitHandler && $attempt < $this->rateLimitRetries) {
            ++$attempt;
            ($this->rateLimitHandler)($attempt);

            return $this->call($callback, $attempt);
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
            $answer = $this->call(fn () => $this->forgeClient->getApi('issue')->show($id));
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
            $issueWithJournals = $this->call(fn () => $this->forgeClient->getApi('issue')->show($issue['id'], ['include' => ['journals']]));
            if (!is_array($issueWithJournals) || !isset($issueWithJournals['issue'])) {
                // Forge answered without the issue, it keeps its previous state in the index
                throw new \RuntimeException(sprintf('Loading the journals failed, Forge answered: %s', mb_substr(is_string($issueWithJournals) ? trim($issueWithJournals) : get_debug_type($issueWithJournals), 0, 200)));
            }
            $issue = $this->projectIssues->withoutPrivateNotes($issueWithJournals['issue']);
        }

        $document = self::document($this->withUserEmails($issue));
        $index = ElasticUtility::getIndexByName('issue');
        $index->addDocument(new Document((string) $document['id'], $document));
        if ($refresh) {
            $index->refresh();
        }
        $this->recorder->document();
    }

    /**
     * The document of the issue index for an issue as Forge sends it: the custom fields as fields of their own,
     * the dates in the format of the index.
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
        $issue['updated_on'] = self::indexDate((string) $issue['updated_on']);
        $issue['created_on'] = self::indexDate((string) $issue['created_on']);
        unset($issue['start_date'], $issue['due_date']);

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
     * Adds the email of the author and of the assignee, for their avatars.
     *
     * @param array<string, mixed> $issue
     *
     * @return array<string, mixed>
     */
    private function withUserEmails(array $issue): array
    {
        // The author could be empty, so we have to check for that
        $issue['author']['email'] = $this->userEmail($issue['author']['id'] ?? null);
        if (isset($issue['assigned_to']['id'])) {
            // A group can be assigned, too: Forge has no user for it, so it gets no email
            $issue['assigned_to']['email'] = $this->userEmail($issue['assigned_to']['id']);
        }

        return $issue;
    }

    /**
     * The email of a Forge user, empty if Forge does not know it.
     */
    private function userEmail(int|string|null $userId): string
    {
        if (null === $userId) {
            return '';
        }
        if (!isset($this->userCache[$userId])) {
            // A rate limit (429) is thrown and not cached, the next run asks again
            $this->userCache[$userId] = $this->cache->get('forge_user_mail_' . (int) $userId, function (ItemInterface $item) use ($userId): string {
                $item->expiresAfter(self::USER_CACHE_LIFETIME);
                try {
                    $userRecord = $this->call(fn () => $this->forgeClient->getApi('user')->show($userId));
                } catch (\RuntimeException $e) {
                    // E.g. a deleted or locked user (404, 403): the issue is imported without the email
                    if (429 === $e->getCode()) {
                        throw $e;
                    }
                    $userRecord = null;
                }

                return is_array($userRecord) ? ($userRecord['user']['mail'] ?? '') : '';
            });
        }

        return $this->userCache[$userId];
    }
}
