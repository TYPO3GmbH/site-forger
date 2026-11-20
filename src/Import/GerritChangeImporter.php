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
use App\Document\CommitMessage;
use App\Document\ReviewSubject;
use App\Elastic\Indices;
use App\Enum\ReviewVoteEnum;
use App\Http\ApiClient;
use App\Http\ApiFailure;
use App\People\User;
use App\People\Users;
use Elastica\Document;

/**
 * Writes Gerrit changes to the review index, as the import and the refresh of a single
 * change do.
 *
 * The requests go through App\Http\ApiClient: retries and failures as for every API, see App\Http\ApiRequests.
 */
final class GerritChangeImporter
{
    public const string PROJECT = 'project:Packages/TYPO3.CMS';
    /**
     * The details of a change, the same for the refresh of one change, the full import and the delta: the votes,
     * the current patch set with its files and commit, and the accounts with name, username and email.
     */
    public const array DETAIL_OPTIONS = ['DETAILED_LABELS', 'CURRENT_REVISION', 'CURRENT_FILES', 'CURRENT_COMMIT', 'DETAILED_ACCOUNTS'];
    /**
     * The accounts of the CI, their votes are no reviews.
     */
    public const array CI_ACCOUNTS = [49454, 51735, 52386];
    /**
     * Documents per bulk request to Elasticsearch.
     */
    private const int BULK_SIZE = 500;

    /**
     * Changes per request with their details.
     */
    public const int DETAIL_BATCH = 25;

    public function __construct(
        private readonly Indices $indices,
        private readonly ApiClient $gerrit,
        private readonly CommandRunRecorder $recorder,
        private readonly ProjectChanges $projectChanges,
        private readonly Users $users,
    ) {
    }

    /**
     * @throws ApiFailure the index keeps the change as it is
     */
    public function refresh(int $number): ChangeRefreshResult
    {
        $result = $this->update([$number], refresh: true);
        if ($result['updated'] > 0) {
            return ChangeRefreshResult::UPDATED;
        }

        return $result['removed'] > 0 ? ChangeRefreshResult::REMOVED : ChangeRefreshResult::NOT_FOUND;
    }

    /**
     * Loads the changes from Gerrit and writes them. Changes that Gerrit does not show for the project any more are
     * removed: private, deleted, or in another project.
     *
     * @param list<int>               $numbers
     * @param (\Closure(): void)|null $afterChange called after each change Gerrit shows
     * @param bool                    $refresh     the index shows the changes right away
     *
     * @return array{updated: int, removed: int}
     *
     * @throws ApiFailure
     */
    public function update(array $numbers, ?\Closure $afterChange = null, bool $refresh = false): array
    {
        $updated = 0;
        $removed = 0;
        foreach (array_chunk($numbers, self::DETAIL_BATCH) as $batch) {
            $documents = $this->documents($this->find(self::PROJECT . ' (' . self::changes($batch) . ')', count($batch)), $afterChange);
            $this->writeAll($documents, $refresh);
            $updated += count($documents);
            $removed += $this->remove(array_values(array_diff($batch, array_keys($documents))));
        }

        return ['updated' => $updated, 'removed' => $removed];
    }

    /**
     * @param list<int> $numbers
     *
     * @return int the number of removed changes
     */
    public function remove(array $numbers): int
    {
        return [] === $numbers ? 0 : $this->projectChanges->remove($numbers);
    }

    /**
     * @param array<int|string> $numbers
     */
    public static function changes(array $numbers): string
    {
        return implode(' OR ', array_map(static fn (int|string $number): string => 'change:' . $number, $numbers));
    }

    /**
     * Writes in bulk requests: one request for each change took longer than loading it from Gerrit.
     *
     * @param array<array<string, mixed>> $documents see documents()
     * @param bool                        $refresh   the index shows the changes right away
     */
    public function writeAll(array $documents, bool $refresh = false): void
    {
        // The owners the index "user" lacks, complete: Gerrit told all of them with the change
        $this->users->ensureGerrit(array_values(array_filter(array_map(self::owner(...), array_values($documents)))));
        foreach (array_chunk(array_values($documents), self::BULK_SIZE) as $chunk) {
            $this->indices->reviews()->addDocuments(array_map(static fn (array $document): Document => new Document((string) $document['id'], $document), $chunk));
            $this->recorder->document(count($chunk));
        }
        if ($refresh && [] !== $documents) {
            $this->indices->reviews()->refresh();
        }
    }

    /**
     * The owner of a change as a person of the index "user", null for a change imported before the owner's account.
     *
     * @param array<string, mixed> $document see documents()
     */
    public static function owner(array $document): ?User
    {
        return isset($document['owner_id']) ? User::fromGerrit([
            '_account_id' => $document['owner_id'],
            'name' => $document['owner'] ?? '',
            'username' => $document['owner_username'] ?? null,
            'email' => $document['ownerEmail'] ?? null,
        ]) : null;
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
     * @throws ApiFailure Gerrit could not be asked
     */
    public function find(string $query, int $count, int $start = 0, array $options = self::DETAIL_OPTIONS): array
    {
        return array_values($this->gerrit->object('/changes/', ['q' => $query, 'n' => $count, 'start' => $start, 'o' => $options], 'a list of changes'));
    }

    /**
     * The documents of the review index for changes as Gerrit sends them with DETAIL_OPTIONS, by their number.
     * Raise ImportSchema::REVIEW when they take more or other data.
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
                // The owner of the change, who uploaded it, with the account of Gerrit; the author of its commit
                // before, who has no account, for the changes Gerrit tells no owner of
                'owner' => $change['owner']['name'] ?? $author ?? 'Unknown',
                'ownerEmail' => $change['owner']['email'] ?? $authorEmail ?? 'Unknown',
                'owner_id' => isset($change['owner']['_account_id']) ? (int) $change['owner']['_account_id'] : null,
                'owner_username' => $change['owner']['username'] ?? null,
                'files' => $filesInPatchset,
                'work_in_progress' => ReviewSubject::isWorkInProgress($reviewTags) || ($change['work_in_progress'] ?? false),
                'commit_message' => $commitMessage,
                ImportSchema::FIELD => ImportSchema::REVIEW,
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
}
