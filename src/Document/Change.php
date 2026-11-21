<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Enum\ActivityEnum;
use App\Enum\ReviewLabelEnum;
use App\Enum\ReviewLabelStateEnum;
use App\Enum\ReviewSizeEnum;
use App\Enum\ReviewStateEnum;
use App\Enum\ReviewStatusEnum;
use App\Enum\ReviewTypeEnum;
use App\Enum\ReviewVoteEnum;
use App\People\Person;

/**
 * A Gerrit change as the review index has it, see GerritChangeImporter::documents().
 *
 * Documents of older imports lack some fields. These fields are null then.
 */
final readonly class Change
{
    private const string NO_TOPIC = 'no topic';

    /**
     * @param list<string>          $releases       the versions of the footer "Releases:"
     * @param list<string>          $reviewTags     the tags of the subject, like "BUGFIX"
     * @param list<string>          $files          the paths the current patch set changes
     * @param list<int>             $resolves       the issues of the footer "Resolves:"
     * @param list<int>             $related        the issues of the footer "Related:"
     * @param array<string, int>    $votes          the sums and the lowest and highest votes of the labels, see vote()
     * @param list<ChangedFile>     $fileDetails    the files with their lines, the paths only for older imports
     * @param list<SingleVote>      $singleVotes    each vote with who gave it, the CI too
     * @param array<string, string> $labelStates    what each label lacks for a merge, by its name: OK, NEED, REJECT,
     *                                              IMPOSSIBLE or MAY
     * @param list<ReviewMessage>   $messages       the review log, the oldest first
     * @param list<string>          $conflicts      the files with a conflict in the current patch set
     * @param int|null              $cherryPickOf   the change this one is a cherry-pick of
     * @param list<ChainLink>       $chain          the relation chain of an open change, the top first, the change
     *                                              itself among them; empty without a chain
     * @param list<CodeComment>     $comments       the comments on the code and on the patch sets, the oldest first
     * @param list<int>             $mergeConflicts the open changes that conflict with this open one, by number
     */
    public function __construct(
        public int $id,
        public string $subject,
        public ?string $changeId = null,
        public ?string $status = null,
        public ?string $branch = null,
        public ?string $topic = null,
        public array $releases = [],
        public ?bool $mergeable = null,
        public array $reviewTags = [],
        public ?int $affectedFiles = null,
        public ?int $insertions = null,
        public ?int $deletions = null,
        public ?int $patchsets = null,
        public ?string $createdOn = null,
        public ?string $updatedOn = null,
        public ?string $owner = null,
        public ?int $ownerId = null,
        public ?string $ownerUsername = null,
        public ?string $ownerEmail = null,
        public array $files = [],
        public bool $workInProgress = false,
        public string $commitMessage = '',
        public array $resolves = [],
        public array $related = [],
        private array $votes = [],
        public array $fileDetails = [],
        public array $singleVotes = [],
        public array $labelStates = [],
        public array $messages = [],
        public array $conflicts = [],
        public ?int $cherryPickOf = null,
        public ?int $commentCount = null,
        public ?int $unresolvedCommentCount = null,
        public array $chain = [],
        public array $comments = [],
        public array $mergeConflicts = [],
    ) {
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromDocument(array $document): self
    {
        $votes = [];
        foreach (ReviewLabelEnum::cases() as $label) {
            foreach ($label->fields() as $field) {
                if (isset($document[$field])) {
                    $votes[$field] = (int) $document[$field];
                }
            }
        }

        return new self(
            id: (int) ($document['id'] ?? 0),
            subject: (string) ($document['subject'] ?? ''),
            changeId: self::stringOrNull($document['change_id'] ?? null),
            status: self::stringOrNull($document['reviewstatus'] ?? null),
            branch: self::stringOrNull($document['branch'] ?? null),
            topic: self::stringOrNull($document['topic'] ?? null),
            releases: array_values(array_map('strval', (array) ($document['releases'] ?? []))),
            mergeable: isset($document['mergeable']) ? 'no' !== $document['mergeable'] : null,
            reviewTags: array_values(array_map('strval', (array) ($document['reviewtags'] ?? []))),
            affectedFiles: self::intOrNull($document['affected_files'] ?? null),
            insertions: self::intOrNull($document['insertions'] ?? null),
            deletions: self::intOrNull($document['deletions'] ?? null),
            patchsets: self::intOrNull($document['patchsets'] ?? null),
            createdOn: self::stringOrNull($document['created_on'] ?? null),
            updatedOn: self::stringOrNull($document['updated_on'] ?? null),
            owner: self::stringOrNull($document['owner'] ?? null),
            ownerId: self::intOrNull($document['owner_id'] ?? null),
            ownerUsername: self::stringOrNull($document['owner_username'] ?? null),
            ownerEmail: self::stringOrNull($document['ownerEmail'] ?? null),
            files: array_values(array_map('strval', (array) ($document['files'] ?? []))),
            workInProgress: (bool) ($document['work_in_progress'] ?? false),
            commitMessage: (string) ($document['commit_message'] ?? ''),
            resolves: array_values(array_map('intval', (array) ($document['resolves'] ?? []))),
            related: array_values(array_map('intval', (array) ($document['related'] ?? []))),
            votes: $votes,
            fileDetails: self::fileDetails($document),
            singleVotes: array_values(array_filter(array_map(
                static fn (mixed $vote): ?SingleVote => is_array($vote) ? SingleVote::fromDocument($vote) : null,
                (array) ($document['votes'] ?? []),
            ))),
            labelStates: array_map('strval', (array) ($document['label_states'] ?? [])),
            messages: array_values(array_map(
                ReviewMessage::fromDocument(...),
                array_filter((array) ($document['messages'] ?? []), is_array(...)),
            )),
            conflicts: array_values(array_map('strval', (array) ($document['conflicts'] ?? []))),
            cherryPickOf: self::intOrNull($document['cherry_pick_of'] ?? null),
            commentCount: self::intOrNull($document['comment_count'] ?? null),
            unresolvedCommentCount: self::intOrNull($document['unresolved_comment_count'] ?? null),
            chain: self::chain($document),
            comments: array_values(array_map(
                CodeComment::fromDocument(...),
                array_filter((array) ($document['comments'] ?? []), is_array(...)),
            )),
            mergeConflicts: array_values(array_map(intval(...), (array) ($document['merge_conflicts'] ?? []))),
        );
    }

    /**
     * The comments Gerrit published with a message of the review log, on the patch set first, then by file and line.
     *
     * @return list<CodeComment>
     */
    public function commentsOf(ReviewMessage $message): array
    {
        if (null === $message->id) {
            return [];
        }
        $comments = array_values(array_filter(
            $this->comments,
            static fn (CodeComment $comment): bool => $message->id === $comment->messageId,
        ));
        usort($comments, static fn (CodeComment $a, CodeComment $b): int => [null !== $a->file, $a->file, $a->line]
            <=> [null !== $b->file, $b->file, $b->line]);

        return $comments;
    }

    /**
     * How many comments a file has, on any patch set.
     */
    public function commentCountOf(string $path): int
    {
        return count(array_filter($this->comments, static fn (CodeComment $comment): bool => $path === $comment->file));
    }

    /**
     * Whether a comment is the last one of a thread that is not resolved: what the owner still has to answer.
     */
    public function isOpen(CodeComment $comment): bool
    {
        return $comment->unresolved && in_array($comment->id, $this->lastOfThreads(), true);
    }

    /**
     * The last comment of each thread, by the comment that starts it.
     *
     * @return list<string>
     */
    private function lastOfThreads(): array
    {
        $parents = [];
        foreach ($this->comments as $comment) {
            $parents[$comment->id] = $comment->inReplyTo;
        }
        $last = [];
        foreach ($this->comments as $comment) {
            $root = $comment->id;
            // Up the replies to the comment that starts the thread, as far as the change has them
            while (null !== ($parents[$root] ?? null) && array_key_exists((string) $parents[$root], $parents)) {
                $root = (string) $parents[$root];
            }
            $last[$root] = $comment->id;
        }

        return array_values($last);
    }

    /**
     * Whether the change is part of a relation chain: Gerrit names more changes than the change itself.
     */
    public function hasChain(): bool
    {
        return count($this->chain) > 1;
    }

    /**
     * The changes of the chain based on this one, the top first.
     *
     * @return list<ChainLink>
     */
    public function chainAbove(): array
    {
        return array_slice($this->chain, 0, $this->chainPosition());
    }

    /**
     * The changes of the chain this one is based on, the nearest first.
     *
     * @return list<ChainLink>
     */
    public function chainBelow(): array
    {
        return array_slice($this->chain, $this->chainPosition() + 1);
    }

    /**
     * The link of the change itself in its chain, null without a chain.
     */
    public function chainLink(): ?ChainLink
    {
        return $this->chain[$this->chainPosition()] ?? null;
    }

    private function chainPosition(): int
    {
        foreach ($this->chain as $position => $link) {
            if ($this->id === $link->number) {
                return $position;
            }
        }

        return count($this->chain);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<ChainLink>
     */
    private static function chain(array $document): array
    {
        return array_values(array_map(
            ChainLink::fromDocument(...),
            array_filter((array) ($document['chain'] ?? []), is_array(...)),
        ));
    }

    /**
     * An account of the review log or of a vote, as the import keeps it: number, name, username and email.
     */
    public static function account(mixed $account): ?Person
    {
        if (!is_array($account) || '' === trim((string) ($account['name'] ?? ''))) {
            return null;
        }

        return new Person(
            trim((string) $account['name']),
            gerritId: isset($account['id']) ? (int) $account['id'] : null,
            username: isset($account['username']) ? (string) $account['username'] : null,
            email: isset($account['email']) ? (string) $account['email'] : null,
        );
    }

    /**
     * What a label lacks for a merge, null when Gerrit did not tell or the change was imported before.
     */
    public function labelState(ReviewLabelEnum $label): ?ReviewLabelStateEnum
    {
        return ReviewLabelStateEnum::tryFrom($this->labelStates[$label->value] ?? '');
    }

    /**
     * The votes on a label, those of people first, the strongest first.
     *
     * @return list<SingleVote>
     */
    public function votesOn(ReviewLabelEnum $label): array
    {
        $votes = array_values(array_filter(
            $this->singleVotes,
            static fn (SingleVote $vote): bool => $label === $vote->label,
        ));
        usort(
            $votes,
            static fn (SingleVote $a, SingleVote $b): int => [$a->isByCi(), -abs($a->value)]
                <=> [$b->isByCi(), -abs($b->value)],
        );

        return $votes;
    }

    /**
     * Whether a file has a conflict in the current patch set.
     */
    public function hasConflict(string $path): bool
    {
        return in_array($path, $this->conflicts, true);
    }

    /**
     * The files with their lines. A change imported before version 4 of the review import has the paths only.
     *
     * @param array<string, mixed> $document
     *
     * @return list<ChangedFile>
     */
    private static function fileDetails(array $document): array
    {
        if (isset($document['file_details']) && is_array($document['file_details'])) {
            return array_values(array_map(
                ChangedFile::fromDocument(...),
                array_filter($document['file_details'], is_array(...)),
            ));
        }

        return array_values(array_map(
            static fn (mixed $path): ChangedFile => new ChangedFile((string) $path),
            (array) ($document['files'] ?? []),
        ));
    }

    public function state(): ReviewStateEnum
    {
        return match (ReviewStatusEnum::tryFrom((string) $this->status)) {
            ReviewStatusEnum::MERGED => ReviewStateEnum::MERGED,
            ReviewStatusEnum::ABANDONED => ReviewStateEnum::ABANDONED,
            default => $this->workInProgress ? ReviewStateEnum::WORK_IN_PROGRESS : ReviewStateEnum::READY,
        };
    }

    /**
     * Gerrit cannot merge the open change, it needs a rebase.
     */
    public function isConflict(): bool
    {
        $open = null === $this->status || ReviewStatusEnum::NEW->value === $this->status;

        return false === $this->mergeable && $open;
    }

    public function hasTopic(): bool
    {
        return null !== $this->topic && self::NO_TOPIC !== $this->topic;
    }

    public function size(): ?ReviewSizeEnum
    {
        if (null === $this->insertions || null === $this->deletions) {
            return null;
        }

        return ReviewSizeEnum::fromLines($this->insertions + $this->deletions);
    }

    public function type(): ?ReviewTypeEnum
    {
        return ReviewTypeEnum::fromTags($this->reviewTags);
    }

    /**
     * The vote of a label, from its lowest and highest single vote. Documents of older imports have only the sums.
     */
    public function vote(ReviewLabelEnum $label): ReviewVoteEnum
    {
        $lowest = $this->votes[$label->lowestField()] ?? null;
        $highest = $this->votes[$label->highestField()] ?? null;
        if (null !== $lowest && null !== $highest) {
            $value = $lowest < 0 ? max($lowest, -2) : min(max($highest, 0), 2);

            return ReviewVoteEnum::tryFrom($value) ?? ReviewVoteEnum::NONE;
        }
        if ($this->negativeVotes($label) > 0) {
            return ReviewVoteEnum::DISLIKED;
        }

        return $this->positiveVotes($label) > 0 ? ReviewVoteEnum::RECOMMENDED : ReviewVoteEnum::NONE;
    }

    public function positiveVotes(ReviewLabelEnum $label): int
    {
        return $this->votes[$label->positiveField()] ?? 0;
    }

    public function negativeVotes(ReviewLabelEnum $label): int
    {
        return $this->votes[$label->negativeField()] ?? 0;
    }

    /**
     * The Git ref of the current patch set, like "refs/changes/34/81234/3". Gerrit puts the last two digits of the
     * number in front, so that no folder gets too many refs. Null for a closed change: it has nothing to test.
     */
    public function patchSetRef(): ?string
    {
        if ($this->state()->isClosed() || null === $this->patchsets) {
            return null;
        }

        return sprintf('refs/changes/%02d/%d/%d', $this->id % 100, $this->id, $this->patchsets);
    }

    /**
     * How long nothing happened to the open change, null for a closed one.
     */
    public function activity(\DateTimeInterface $now): ?ActivityEnum
    {
        if ($this->state()->isClosed()) {
            return null;
        }
        $dates = ['updated_on' => $this->updatedOn, 'created_on' => $this->createdOn];
        $lastActivity = IndexDates::lastActivity($dates);

        return null === $lastActivity ? null : ActivityEnum::fromDate($lastActivity, $now);
    }

    /**
     * The owner as a Person. Null without an owner.
     */
    public function ownerPerson(): ?Person
    {
        $name = trim((string) $this->owner);

        return '' === $name ? null : new Person($name, gerritId: $this->ownerId, email: $this->ownerEmail);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return null === $value ? null : (int) $value;
    }
}
