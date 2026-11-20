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
use App\Enum\ReviewSizeEnum;
use App\Enum\ReviewStateEnum;
use App\Enum\ReviewStatusEnum;
use App\Enum\ReviewTypeEnum;
use App\Enum\ReviewVoteEnum;

/**
 * A Gerrit change as the review index has it, see GerritChangeImporter::documents().
 *
 * Documents of older imports lack some fields. These fields are null then.
 */
final readonly class Change
{
    private const string NO_TOPIC = 'no topic';

    /**
     * @param list<string>       $releases   the versions of the footer "Releases:"
     * @param list<string>       $reviewTags the tags of the subject, like "BUGFIX"
     * @param list<string>       $files      the paths the current patch set changes
     * @param list<int>          $resolves   the issues of the footer "Resolves:"
     * @param list<int>          $related    the issues of the footer "Related:"
     * @param array<string, int> $votes      the sums and the lowest and highest votes of the labels, see vote()
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
        );
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
     * The owner as the user macros take a person, see templates/_macros/user.html.twig. Null without an owner.
     *
     * @return array{name: string, email: string|null, gerrit_id: int|null}|null
     */
    public function ownerAsUser(): ?array
    {
        $name = trim((string) $this->owner);

        return '' === $name ? null : ['name' => $name, 'email' => $this->ownerEmail, 'gerrit_id' => $this->ownerId];
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
