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
use App\Elastic\ReviewSubject;
use App\Enum\ReviewVoteEnum;
use Elastica\Document;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;

/**
 * Writes Gerrit changes and their interactions to the indices, for the import.
 *
 * Gerrit rate-limits the heavy queries now and then (429) or fails (5xx). Without a handler, see onRetry(),
 * that is an error at once: a page waiting for minutes helps nobody. The import waits and tries again.
 */
final class GerritChangeImporter
{
    public const string PROJECT = 'project:Packages/TYPO3.CMS';
    public const array DETAIL_OPTIONS = ['DETAILED_LABELS', 'ALL_REVISIONS', 'ALL_FILES', 'CURRENT_COMMIT', 'MESSAGES', 'DETAILED_ACCOUNTS'];
    /**
     * The accounts of the CI, their votes are no reviews.
     */
    public const array CI_ACCOUNTS = [49454, 51735, 52386];

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
     * Writes the document of a change to the review index.
     *
     * @param array<string, mixed> $document see documents()
     * @param bool                 $refresh  whether the index shows the change right away
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
     * Writes an interaction, of Gerrit or GitHub, to the interactions index.
     *
     * @param array<string, mixed> $interaction
     */
    public function writeInteraction(array $interaction): void
    {
        ElasticUtility::getIndexByName('interactions')->addDocument(new Document($interaction['interaction_id'] . '-' . $interaction['interaction_type'], $interaction));
        $this->recorder->document();
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
     * Their interactions (patch sets, comments, votes, merges, abandons) are written on the way, writing them
     * twice does no harm.
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
            $labels = self::labels($change['labels']);
            if (!array_key_exists('mergeable', $change)) {
                $change['mergeable'] = false;
            }

            if (!array_key_exists('current_revision', $change)) {
                continue;
            }

            $patchSetNumber = 0;
            $interaction = [];
            foreach ($change['messages'] as $message) {
                $interaction['fullname'] = '';
                $interaction['email'] = '';
                try {
                    $interaction['interaction_id'] = $message['id'];
                    $messageId = $message['id'];
                    $interaction['interaction_source'] = 'Gerrit';
                    $interaction['interaction_source_id'] = $change['_number'];
                    $interaction['interacted_on'] = self::gerritDate($message['date']);

                    $message['_revision_number'] ??= $patchSetNumber;

                    // Check if is merge-commit
                    if ($this->checkIfMessageIsMerge($message['message'])) {
                        $revisions = $change['revisions'];
                        usort($revisions, function ($a, $b) {
                            $a = new \DateTime(self::gerritDate($a['created']));
                            $b = new \DateTime(self::gerritDate($b['created']));

                            return $a->getTimestamp() <=> $b->getTimestamp();
                        });

                        $mergeRevision = array_last($revisions);
                        $this->writeInteraction($this->buildRevisionArray($mergeRevision, 'core:review:merge', $messageId) + $interaction);

                        // If there is more than one revision, we want to give credit to both the original author
                        // and the person that patched this change with improvements (in case that is a different person)
                        if ((is_countable($revisions) ? count($revisions) : 0) > 1) {
                            $firstRevision = array_first($revisions);
                            // Only applicable if the first and merge revision was done by different people
                            if ($firstRevision['uploader']['_account_id'] !== $mergeRevision['uploader']['_account_id']) {
                                $this->writeInteraction($this->buildRevisionArray($firstRevision, 'core:review:merge-author', $messageId) + $interaction);
                            }

                            $lastRevisionBeforeMerge = $revisions[array_key_last($revisions) - 1];
                            // Only applicable if the first and last and merge revision was done by different people
                            if (
                                $lastRevisionBeforeMerge['uploader']['_account_id'] !== $mergeRevision['uploader']['_account_id']
                                && $lastRevisionBeforeMerge['uploader']['_account_id'] !== $firstRevision['uploader']['_account_id']
                            ) {
                                $this->writeInteraction($this->buildRevisionArray($lastRevisionBeforeMerge, 'core:review:merge-lastpatchset', $messageId) + $interaction);
                            }
                        }
                    } elseif ($this->checkIfMessageIsBackport($message['message'])) {
                        // code intentionally left blank
                    } else {
                        $interaction['account'] = $message['author']['username'];
                        $interaction['fullname'] = $message['author']['name'];
                        if (isset($message['author']['email'])) {
                            $interaction['email'] = $message['author']['email'];
                        }
                        // Is patchset, add as event
                        if ($patchSetNumber < $message['_revision_number']) {
                            $patchSetNumber = $message['_revision_number'];
                            $interaction['interaction_type'] = 'core:review:patchset';
                            $this->writeInteraction($interaction);
                        }
                        // Check if message is "just" a new patchset
                        if ($this->checkIfMessageIsComment($message['message'])) {
                            $interaction['interaction_type'] = 'core:review:comment';
                            $this->writeInteraction($interaction);
                        }
                        // Check if message is a code review
                        if ($this->checkIfMessageIsCodeReview($message['message'])) {
                            $interaction['interaction_type'] = 'core:review:codereview';
                            $this->writeInteraction($interaction);
                        }
                        // Check if message is a verification
                        if ($this->checkIfMessageIsVerification($message['message'])) {
                            $interaction['interaction_type'] = 'core:review:testing';
                            $this->writeInteraction($interaction);
                        }
                        // Check if message is abandoning
                        if ($this->checkIfMessageIsAbandoning($message['message'])) {
                            $interaction['interaction_type'] = 'core:review:abandon';
                            $this->writeInteraction($interaction);
                        }
                    }
                } catch (\Exception $e) {
                    if (
                        !str_contains((string) $message['message'], 'Change could not be merged due to a path conflict')
                        && !str_contains((string) $message['message'], 'The change could not be merged due to a path conflict')
                        && !str_contains((string) $message['message'], 'Your change could not be merged due to a path conflict')
                        && !str_contains((string) $message['message'], 'Change has been successfully pushed into branch sandbox')
                        && !str_contains((string) $message['message'], 'Change could not be merged because of a missing dependency')
                    ) {
                        throw new \RuntimeException($e->getMessage());
                    }
                }
            }

            $author = $change['revisions'][$change['current_revision']]['commit']['author']['name'];
            $authorEmail = $change['revisions'][$change['current_revision']]['commit']['author']['email'];
            $filesInPatchset = array_keys($change['revisions'][$change['current_revision']]['files']);
            if (11918 === (int) $change['_number']) {
                $change['revisions'][$change['current_revision']]['commit']['message'] = '6.0';
            }

            $reviewTags = ReviewSubject::tags($change['subject']);

            $out[$change['_number']] = [
                'id' => $change['_number'],
                'change_id' => $change['change_id'],
                'reviewstatus' => $change['status'],
                'subject' => $change['subject'],
                'branch' => 'master' === $change['branch'] ? 'main' : $change['branch'],
                'topic' => array_key_exists('topic', $change) ? $change['topic'] : 'no topic',
                'mergeable' => (true === $change['mergeable']) ? 'yes' : 'no',
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
                'releases' => self::releases(
                    $change['revisions'][$change['current_revision']]['commit']['message']
                ),
                'owner' => $author ?? 'Unknown',
                'ownerEmail' => $authorEmail ?? 'Unknown',
                'files' => $filesInPatchset,
                'work_in_progress' => ReviewSubject::isWorkInProgress($reviewTags) || ($change['work_in_progress'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * The releases of the commit message, as "Releases: main, 13.4" names them, ["omitted"] without the line.
     *
     * @return list<string>
     *
     * @throws \Exception
     */
    public static function releases(string $message): array
    {
        // Fix fucked up lines in message
        $searches = ['Release: ',  '7,6'];
        $replaces = ['Releases: ', '7.6'];
        $message = str_replace($searches, $replaces, $message);
        $pattern = '/Releases:\s*(.*)/';
        preg_match($pattern, $message, $matches);
        if (!isset($matches[1])) {
            return ['omitted'];
        }

        $search = ['master.', 'master', '4.7.', '4.5 LTS'];
        $replaces = ['master,', 'main', '4.7,', '4.5'];
        $matches[1] = str_replace($search, $replaces, $matches[1]);
        $releaseArray = array_map(trim(...), explode(',', $matches[1]));
        foreach ($releaseArray as $key => $release) {
            if (str_contains((string) $release, ' ')) {
                throw new \InvalidArgumentException('Release contains space? ' . $message);
            }
            $releaseArray[$key] = strtolower((string) $release);
        }

        return $releaseArray;
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

    private function checkIfMessageIsComment(string $message): bool
    {
        // Is just a Code Review
        $pattern = '^Patch\sSet\s\d{1,4}:\s.*(Code-Review)(-|\+)(1|2)$';
        $isCodeReview = $this->checkRegex($message, $pattern, '');
        // Is just a Verification
        $pattern = '^Patch\sSet\s\d{1,4}:\s.*(Verified)(-|\+)(1|2)$';
        $isVerification = $this->checkRegex($message, $pattern, '');
        // Is just a Verification
        $pattern = '^Uploaded\spatch\sset\s\d{1,4}.$';
        $isPatchset = $this->checkRegex($message, $pattern, '');

        $pattern = '^Patch\sSet\s\d{1,4}:\sCommit message was updated\.$';
        $isCommitMessageChange = $this->checkRegex($message, $pattern, '');

        // Check all booleans and if one hits, return false
        return !($isCodeReview || $isVerification || $isPatchset || $isCommitMessageChange || $this->checkIfMessageIsBackport($message) || $this->checkIfMessageIsMerge($message));
    }

    private function checkIfMessageIsCodeReview(string $message): bool
    {
        $pattern = '^Patch\sSet\s\d{1,4}:\s.*(Code-Review)(-|\+)(1|2)';

        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsVerification(string $message): bool
    {
        $pattern = '^Patch\sSet\s\d{1,4}:\s.*(Verified)(-|\+)(1|2)';

        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsAbandoning(string $message): bool
    {
        $pattern = '^Abandoned';

        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsMerge(string $message): bool
    {
        $pattern = 'Change has been successfully cherry-picked as \b[0-9a-f]{40}\b';

        return $this->checkRegex($message, $pattern);
    }

    private function checkIfMessageIsBackport(string $message): bool
    {
        $pattern = 'This patchset was cherry picked to branch.*? as commit \b[0-9a-f]{40}\b';

        return $this->checkRegex($message, $pattern);
    }

    private function checkRegex(string $string, string $pattern, $modifier = 'm'): bool
    {
        preg_match('/' . $pattern . '/' . $modifier, $string, $matches);

        return isset($matches[0]);
    }

    private function buildRevisionArray(array $data, string $interactionType, string $messageId): array
    {
        $interaction = [];
        $interaction['interaction_id'] = $messageId . '-' . $interactionType;
        $interaction['interaction_type'] = $interactionType;
        $interaction['account'] = $data['uploader']['username'];
        $interaction['fullname'] = $data['uploader']['name'];
        if (isset($data['uploader']['email'])) {
            $interaction['email'] = $data['uploader']['email'];
        } else {
            $interaction['email'] = 'Unknown';
        }

        return $interaction;
    }
}
