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
use App\Enum\ReviewLabelEnum;
use App\Http\ApiClient;
use App\Http\ApiFailure;
use App\People\User;
use App\Repository\UserRepository;
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
     * the current patch set with its files and commit, the review log, and the accounts with name, username and
     * email.
     */
    public const array DETAIL_OPTIONS = [
        'DETAILED_LABELS',
        'CURRENT_REVISION',
        'CURRENT_FILES',
        'CURRENT_COMMIT',
        'DETAILED_ACCOUNTS',
        'MESSAGES',
    ];
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

    /**
     * The sentence of the review log above the files with a conflict, from a rebase or a cherry-pick.
     */
    private const string CONFLICTS = 'The following files contain Git conflicts:';

    /**
     * How bad a state of a label is, when several rules name it: the worst counts.
     */
    private const array LABEL_STATES = ['MAY' => 0, 'OK' => 1, 'NEED' => 2, 'IMPOSSIBLE' => 3, 'REJECT' => 4];

    /**
     * The status Gerrit sends for each file, as the index keeps it. Without one a file is modified.
     */
    private const array FILE_STATUS = [
        'A' => 'added',
        'D' => 'deleted',
        'R' => 'renamed',
        'C' => 'copied',
        'W' => 'rewritten',
    ];

    public function __construct(
        private readonly Indices $indices,
        private readonly ApiClient $gerrit,
        private readonly CommandRunRecorder $recorder,
        private readonly ProjectChanges $projectChanges,
        private readonly UserRepository $users,
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
        $this->users->ensureGerrit(array_merge(...array_map(self::accounts(...), array_values($documents))));
        foreach (array_chunk(array_values($documents), self::BULK_SIZE) as $chunk) {
            $this->indices->reviews()->addDocuments(array_map(static fn (array $document): Document => new Document((string) $document['id'], $document), $chunk));
            $this->recorder->document(count($chunk));
        }
        if ($refresh && [] !== $documents) {
            $this->indices->reviews()->refresh();
        }
    }

    /**
     * The people of a change for the index "user": its owner, and those who voted or wrote in its review log, but
     * the CI. So their avatars and their work are found, too.
     *
     * @param array<string, mixed> $document see documents()
     *
     * @return list<User>
     */
    public static function accounts(array $document): array
    {
        $accounts = array_column((array) ($document['votes'] ?? []), 'account');
        foreach ((array) ($document['messages'] ?? []) as $message) {
            $accounts[] = $message['author'] ?? null;
        }
        $users = array_filter([self::owner($document)]);
        foreach ($accounts as $account) {
            if (is_array($account) && !in_array($account['id'] ?? null, self::CI_ACCOUNTS, true)) {
                $users[] = User::fromGerrit(['_account_id' => $account['id'] ?? null] + $account);
            }
        }

        return array_values(array_filter($users));
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
            ] + CommitMessage::fields($commitMessage) + self::details($change);
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
        $out = [];
        foreach (ReviewLabelEnum::cases() as $label) {
            $out += array_fill_keys($label->fields(), 0);
        }
        foreach ($labels as $name => $votes) {
            $label = ReviewLabelEnum::tryFrom((string) $name);
            if (null === $label) {
                throw new \InvalidArgumentException('Unknown Label type ' . $name);
            }
            foreach ($votes['all'] ?? [] as $vote) {
                if (!isset($vote['value']) || in_array($vote['_account_id'], self::CI_ACCOUNTS, true)) {
                    continue;
                }
                $value = (int) $vote['value'];
                if ($value < -2 || $value > 2) {
                    throw new \InvalidArgumentException('Unknown Vote Type');
                }
                $out[$label->lowestField()] = min($out[$label->lowestField()], $value);
                $out[$label->highestField()] = max($out[$label->highestField()], $value);
                if ($value > 0) {
                    $out[$label->positiveField()] += $value;
                } elseif ($value < 0) {
                    $out[$label->negativeField()] -= $value;
                }
            }
        }

        return $out;
    }

    /**
     * The fields of a change beyond the votes and the commit: the review log, the single votes, the files with
     * their lines, what the labels still lack, the conflicts, and the comments.
     *
     * @param array<string, mixed> $change a change as Gerrit sends it with DETAIL_OPTIONS
     *
     * @return array<string, mixed>
     */
    private static function details(array $change): array
    {
        $messages = (array) ($change['messages'] ?? []);
        $revision = (array) ($change['revisions'][$change['current_revision'] ?? ''] ?? []);
        $patchSet = (int) ($revision['_number'] ?? 0);

        return [
            'messages' => self::messages($messages),
            'votes' => self::singleVotes((array) ($change['labels'] ?? [])),
            'file_details' => self::fileDetails((array) ($revision['files'] ?? [])),
            'label_states' => self::labelStates((array) ($change['submit_records'] ?? [])),
            'conflicts' => self::conflicts($messages, $patchSet),
            'cherry_pick_of' => isset($change['cherry_pick_of_change']) ? (int) $change['cherry_pick_of_change'] : null,
            'comment_count' => (int) ($change['total_comment_count'] ?? 0),
            'unresolved_comment_count' => (int) ($change['unresolved_comment_count'] ?? 0),
        ];
    }

    /**
     * The review log, the oldest first: each message with its author, its date and its patch set.
     *
     * @param array<mixed> $messages
     *
     * @return list<array<string, mixed>>
     */
    public static function messages(array $messages): array
    {
        $log = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $log[] = [
                'author' => self::account($message['author'] ?? null),
                'date' => self::gerritDate((string) ($message['date'] ?? '')),
                'patch_set' => isset($message['_revision_number']) ? (int) $message['_revision_number'] : null,
                'tag' => isset($message['tag']) ? (string) $message['tag'] : null,
                'message' => (string) ($message['message'] ?? ''),
            ];
        }

        return $log;
    }

    /**
     * Each vote of the labels Forger knows, with who gave it and when. The votes of the CI are kept, too.
     *
     * @param array<mixed> $labels the labels Gerrit sends with DETAILED_LABELS
     *
     * @return list<array<string, mixed>>
     */
    public static function singleVotes(array $labels): array
    {
        $votes = [];
        foreach (ReviewLabelEnum::cases() as $label) {
            foreach ((array) ($labels[$label->value]['all'] ?? []) as $vote) {
                if (!is_array($vote) || !isset($vote['value']) || 0 === (int) $vote['value']) {
                    continue;
                }
                $votes[] = [
                    'label' => $label->value,
                    'value' => (int) $vote['value'],
                    'account' => self::account($vote),
                    'date' => isset($vote['date']) ? self::gerritDate((string) $vote['date']) : null,
                ];
            }
        }

        return $votes;
    }

    /**
     * The files of the current patch set with their lines, sorted by path.
     *
     * @param array<mixed> $files the files Gerrit sends with CURRENT_FILES, by path
     *
     * @return list<array<string, mixed>>
     */
    public static function fileDetails(array $files): array
    {
        $details = [];
        foreach ($files as $path => $file) {
            $file = is_array($file) ? $file : [];
            $details[] = [
                'path' => (string) $path,
                'status' => self::FILE_STATUS[$file['status'] ?? ''] ?? 'modified',
                'insertions' => (int) ($file['lines_inserted'] ?? 0),
                'deletions' => (int) ($file['lines_deleted'] ?? 0),
                'binary' => true === ($file['binary'] ?? false),
                'old_path' => isset($file['old_path']) ? (string) $file['old_path'] : null,
            ];
        }
        usort($details, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $details;
    }

    /**
     * What each label lacks for a merge, by its name: OK, NEED, REJECT, IMPOSSIBLE or MAY. When several rules name
     * a label, the worst state counts.
     *
     * @param array<mixed> $records the "submit_records" Gerrit sends
     *
     * @return array<string, string>
     */
    public static function labelStates(array $records): array
    {
        $states = [];
        foreach ($records as $record) {
            foreach ((array) ($record['labels'] ?? []) as $label) {
                $name = (string) ($label['label'] ?? '');
                $state = (string) ($label['status'] ?? '');
                if (null === ReviewLabelEnum::tryFrom($name) || !isset(self::LABEL_STATES[$state])) {
                    continue;
                }
                if (!isset($states[$name]) || self::LABEL_STATES[$state] > self::LABEL_STATES[$states[$name]]) {
                    $states[$name] = $state;
                }
            }
        }

        return $states;
    }

    /**
     * The files with a conflict in the current patch set, as the review log names them.
     *
     * @param array<mixed> $messages
     *
     * @return list<string>
     */
    public static function conflicts(array $messages, int $patchSet): array
    {
        $files = [];
        foreach ($messages as $message) {
            $text = (string) ($message['message'] ?? '');
            $position = strpos($text, self::CONFLICTS);
            if ($patchSet !== (int) ($message['_revision_number'] ?? 0) || false === $position) {
                continue;
            }
            $after = substr($text, $position + strlen(self::CONFLICTS));
            preg_match_all('/^\s*\* (\S.*?)\s*$/m', $after, $matches);
            $files = [...$files, ...$matches[1]];
        }

        return array_values(array_unique($files));
    }

    /**
     * An account as Gerrit sends it: number, name, username and email. The email is kept to match the person with
     * the users of Forge, for the avatars and "My Work". It never leaves Forger, see App\Api\ChangeMapper.
     *
     * @return array{id: int, name: string, username: string|null, email: string|null}|null
     */
    private static function account(mixed $account): ?array
    {
        if (!is_array($account) || !isset($account['_account_id'])) {
            return null;
        }

        return [
            'id' => (int) $account['_account_id'],
            'name' => (string) ($account['name'] ?? ''),
            'username' => isset($account['username']) ? (string) $account['username'] : null,
            'email' => isset($account['email']) ? (string) $account['email'] : null,
        ];
    }
}
