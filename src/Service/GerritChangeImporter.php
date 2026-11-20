<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

use App\Elastic\CommitMessage;
use App\Elastic\ElasticUtility;
use App\Elastic\ReviewSubject;
use App\Enum\ReviewVoteEnum;
use Elastica\Document;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;

/**
 * Writes Gerrit changes to the review index, as the import and the refresh of a single
 * change do.
 *
 * Gerrit rate-limits the heavy queries now and then (429) or fails (5xx). Without a handler, see onRetry(),
 * that is an error at once: a page waiting for minutes helps nobody. The import waits and tries again.
 */
final class GerritChangeImporter
{
    public const string PROJECT = 'project:Packages/TYPO3.CMS';
    /**
     * The details of a change, the same for the refresh of one change, the full import and the delta: the votes
     * and the current patch set with its files and commit.
     */
    public const array DETAIL_OPTIONS = ['DETAILED_LABELS', 'CURRENT_REVISION', 'CURRENT_FILES', 'CURRENT_COMMIT'];
    /**
     * The accounts of the CI, their votes are no reviews.
     */
    public const array CI_ACCOUNTS = [49454, 51735, 52386];
    /**
     * Documents per bulk request to Elasticsearch.
     */
    private const int BULK_SIZE = 500;

    /** @var (\Closure(int, int, int): void)|null */
    private ?\Closure $retryHandler = null;

    private int $retries = 0;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly CommandRunRecorder $recorder,
    ) {
    }

    /**
     * Waits for Gerrit and tries again, the handler gets the status, the seconds to wait and the attempt, and sleeps.
     *
     * @param \Closure(int, int, int): void $handler
     */
    public function onRetry(\Closure $handler, int $retries): void
    {
        $this->retryHandler = $handler;
        $this->retries = $retries;
    }

    /**
     * Loads a change of the project from Gerrit again and writes it to the index, as the import does.
     * A change Gerrit does not find (any more) stays in the index as it is.
     *
     * @throws \RuntimeException Gerrit could not be asked, the index keeps the change as it is
     */
    public function refresh(int $number): ChangeRefreshResult
    {
        $documents = $this->documents($this->find(self::PROJECT . ' change:' . $number, 1));
        if (!isset($documents[$number])) {
            return ChangeRefreshResult::NOT_FOUND;
        }
        $this->write($documents[$number], refresh: true);

        return ChangeRefreshResult::UPDATED;
    }

    /**
     * Writes the document of a change to the review index.
     *
     * @param array<string, mixed> $document see documents()
     * @param bool                 $refresh  whether the index shows the change right away, for the page that asked
     */
    public function write(array $document, bool $refresh = false): void
    {
        $index = ElasticUtility::getIndexByName('review');
        $index->addDocument(new Document((string) $document['id'], $document));
        if ($refresh) {
            $index->refresh();
        }
        $this->recorder->document();
    }

    /**
     * Writes the documents of changes to the review index in bulk requests, as the import does: one request for
     * each change took longer than asking Gerrit for them.
     *
     * @param array<array<string, mixed>> $documents see documents()
     */
    public function writeAll(array $documents): void
    {
        foreach (array_chunk(array_values($documents), self::BULK_SIZE) as $chunk) {
            ElasticUtility::getIndexByName('review')->addDocuments(array_map(static fn (array $document): Document => new Document((string) $document['id'], $document), $chunk));
            $this->recorder->document(count($chunk));
        }
    }

    /**
     * A date of Gerrit ("2026-11-19 17:42:30.000000000") in the format of the index.
     */
    public static function gerritDate(string $date): string
    {
        return new \DateTime(substr($date, 0, 19))->format('Y/m/d H:i:s');
    }

    /**
     * The changes a Gerrit query finds, with the details of $options.
     *
     * @param list<string> $options the details Gerrit adds to every change, none for just the list
     *
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException Gerrit could not be asked
     */
    public function find(string $query, int $count, int $start = 0, array $options = self::DETAIL_OPTIONS): array
    {
        return $this->decode($this->request($query, $count, $start, $options));
    }

    /**
     * @param list<string> $options
     */
    private function request(string $query, int $count, int $start, array $options, int $retry = 0): ResponseInterface
    {
        try {
            $httpQueryData = [
                'q' => $query,
                'n' => $count,
                'start' => $start,
                'o' => $options,
            ];
            // A list of its own: on a retry $query is the Gerrit query again
            $parts = [];
            // http_build_query() doesn't work as it generates o[0]=a&o[1]=b&o[2]=c instead of o=a&o=b&o=c
            foreach ($httpQueryData as $name => $value) {
                $value = (array) $value;
                array_walk_recursive($value, static function ($value) use (&$parts, $name) {
                    $parts[] = urlencode($name) . '=' . urlencode((string) $value);
                });
            }
            $httpQuery = implode('&', $parts);
            $url = sprintf('/changes/?%s', $httpQuery);

            return $this->client->request('GET', $url);
        } catch (ServerException|ClientException $e) {
            $status = $e->getResponse()->getStatusCode();
            // Gerrit rate-limits the heavy change queries now and then with 429
            if ((429 === $status || $e instanceof ServerException) && null !== $this->retryHandler && $retry < $this->retries) {
                ++$retry;
                $retryAfter = (int) $e->getResponse()->getHeaderLine('Retry-After');
                ($this->retryHandler)($status, $retryAfter > 0 ? min($retryAfter, 300) : 30 * $retry, $retry);

                return $this->request($query, $count, $start, $options, $retry);
            }

            throw new \RuntimeException(sprintf('Fetching changes from Gerrit failed with status %d', $status), $status, $e);
        }
    }

    /**
     * The documents of the review index for changes as Gerrit sends them with DETAIL_OPTIONS, by their number.
     *
     * @param list<array<string, mixed>> $changes
     * @param (\Closure(): void)|null    $onChange called before each change, for the progress of the import
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \Exception
     */
    public function documents(array $changes, ?\Closure $onChange = null): array
    {
        $out = [];
        foreach ($changes as $change) {
            if (null !== $onChange) {
                $onChange();
            }
            if (!array_key_exists('current_revision', $change)) {
                continue;
            }
            $labels = self::labels($change['labels']);

            $author = $change['revisions'][$change['current_revision']]['commit']['author']['name'];
            $authorEmail = $change['revisions'][$change['current_revision']]['commit']['author']['email'];
            $filesInPatchset = array_keys($change['revisions'][$change['current_revision']]['files']);

            $reviewTags = ReviewSubject::tags($change['subject']);
            $commitMessage = (string) ($change['revisions'][$change['current_revision']]['commit']['message'] ?? '');

            $out[$change['_number']] = [
                'id' => $change['_number'],
                'change_id' => $change['change_id'],
                'reviewstatus' => $change['status'],
                'subject' => $change['subject'],
                'branch' => 'master' === $change['branch'] ? 'main' : $change['branch'],
                'topic' => array_key_exists('topic', $change) ? $change['topic'] : 'no topic',
                'mergeable' => true === ($change['mergeable'] ?? false) ? 'yes' : 'no',
                'affected_files' => is_countable($change['revisions'][$change['current_revision']]['files']) ? count($change['revisions'][$change['current_revision']]['files']) : 0,
                'insertions' => $change['insertions'],
                'deletions' => $change['deletions'],
                'reviewtags' => $reviewTags,
                'positive_reviews' => $labels['positive_reviews'],
                'negative_reviews' => $labels['negative_reviews'],
                'positive_verifications' => $labels['positive_verifications'],
                'negative_verifications' => $labels['negative_verifications'],
                'lowest_review_vote' => $labels['lowest_review_vote'],
                'highest_review_vote' => $labels['highest_review_vote'],
                'lowest_verification_vote' => $labels['lowest_verification_vote'],
                'highest_verification_vote' => $labels['highest_verification_vote'],
                'patchsets' => $change['revisions'][$change['current_revision']]['_number'],
                'created_on' => self::gerritDate($change['created']),
                'updated_on' => self::gerritDate($change['updated']),
                'owner' => $author ?? 'Unknown',
                'ownerEmail' => $authorEmail ?? 'Unknown',
                'files' => $filesInPatchset,
                'work_in_progress' => ReviewSubject::isWorkInProgress($reviewTags) || ($change['work_in_progress'] ?? false),
                'commit_message' => $commitMessage,
            ] + CommitMessage::fields($commitMessage);
        }

        return $out;
    }

    /**
     * The sums of the positive and the negative votes of each label, and its lowest and highest single vote:
     * only these tell the +2 and -2 of a merger from two +1 or -1, see ReviewVoteEnum.
     *
     * @param array<string, mixed> $labels the labels Gerrit sends with DETAILED_LABELS
     *
     * @return array<string, int>
     *
     * @throws \Exception
     */
    public static function labels(array $labels): array
    {
        $out = [
            'positive_reviews' => 0,
            'negative_reviews' => 0,
            'positive_verifications' => 0,
            'negative_verifications' => 0,
            'lowest_review_vote' => 0,
            'highest_review_vote' => 0,
            'lowest_verification_vote' => 0,
            'highest_verification_vote' => 0,
        ];
        foreach ($labels as $labelType => $subRow) {
            $key = match ($labelType) {
                'Verified' => '_verifications',
                'Code-Review' => '_reviews',
                default => throw new \InvalidArgumentException('Unknown Label type ' . $labelType),
            };
            $voteKey = '_' . array_search($labelType, ReviewVoteEnum::LABELS, true) . '_vote';
            if (array_key_exists('all', $subRow)) {
                foreach ($subRow['all'] as $vote) {
                    if (isset($vote['value']) && !in_array($vote['_account_id'], self::CI_ACCOUNTS, true)) {
                        $out['lowest' . $voteKey] = min($out['lowest' . $voteKey], (int) $vote['value']);
                        $out['highest' . $voteKey] = max($out['highest' . $voteKey], (int) $vote['value']);
                        switch ($vote['value']) {
                            case 1:
                                ++$out['positive' . $key];
                                break;
                            case 2:
                                $out['positive' . $key] += 2;
                                break;
                            case -1:
                                ++$out['negative' . $key];
                                break;
                            case -2:
                                $out['negative' . $key] += 2;
                                break;
                            case 0:
                                // nothing to do here
                                break;
                            default:
                                throw new \InvalidArgumentException('Unknown Vote Type');
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(ResponseInterface $response): array
    {
        return json_decode(str_replace(")]}'", '', (string) $response->getBody()), true, 512, JSON_THROW_ON_ERROR);
    }
}
