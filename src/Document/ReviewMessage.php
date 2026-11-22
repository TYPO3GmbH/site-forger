<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Enum\ReviewEventEnum;
use App\Enum\ReviewLabelEnum;
use App\Enum\ReviewVoteEnum;
use App\Import\GerritChangeImporter;
use App\People\Person;

/**
 * A message of the review log of a change, see GerritChangeImporter::messages(): a vote, a new patch set, a comment
 * or a note of the CI.
 *
 * Gerrit writes the log as text. fromDocument() reads what it tells from it: the event, the votes, the votes a new
 * patch set made outdated, the number of comments on the code, and the comment a person wrote.
 */
final readonly class ReviewMessage
{
    /**
     * A vote in the log, like "Code-Review+2", or a removed one, like "-Code-Review".
     */
    private const string VOTE = '/(?:^|\s)(?<removed>-)?(?<label>Code-Review|Verified)(?<value>[+-]\d)?(?=\s|$)/';

    /**
     * The line Gerrit adds to a comment that marks the change ready for review. It has no tag of its own.
     */
    private const string READY_LINE = 'This change is ready for review.';

    /**
     * @param string|null                                               $tag            what Gerrit wrote it for
     * @param list<array{label: ReviewLabelEnum, vote: ReviewVoteEnum}> $votes          the votes it gives, NONE for a
     *                                                                                  removed one
     * @param list<array{label: ReviewLabelEnum, vote: ReviewVoteEnum}> $outdatedVotes  the votes a new patch set made
     *                                                                                  outdated
     * @param int|null                                                  $inlineComments the comments on the code
     * @param bool|null                                                 $workInProgress whether the change is work in
     *                                                                                  progress after it, null when
     *                                                                                  it does not tell
     */
    public function __construct(
        public ?Person $author,
        public ?string $date,
        public ?int $patchSet,
        public ?string $tag,
        public string $text,
        public ReviewEventEnum $event = ReviewEventEnum::OTHER,
        public array $votes = [],
        public array $outdatedVotes = [],
        public ?int $inlineComments = null,
        public string $comment = '',
        public ?string $id = null,
        public ?bool $workInProgress = null,
    ) {
    }

    /**
     * @param array<string, mixed> $message a message of "messages"
     */
    public static function fromDocument(array $message): self
    {
        $text = trim(str_replace("\r\n", "\n", (string) ($message['message'] ?? '')));
        $lines = explode("\n", $text);
        [$event, $votes, $first] = self::firstLine(array_shift($lines));
        [$outdated, $inline, $body, $ready] = self::body($lines);
        $tag = isset($message['tag']) ? (string) $message['tag'] : null;
        $tagged = self::taggedEvent($tag, $event);
        if (null !== $tagged) {
            // Gerrit wrote the first line, like "Patch Set 1: Cherry Picked from branch main.": no comment
            $first = ReviewEventEnum::UPLOADED === $tagged && $tagged !== $event ? '' : $first;
            $event = $tagged;
        }
        $comment = trim($first . "\n\n" . $body);
        $author = Change::account($message['author'] ?? null);
        // The CI votes with a note on every patch set, a person who writes more comments
        if (ReviewEventEnum::VOTED === $event && '' !== $comment && !self::isCi($author)) {
            $event = ReviewEventEnum::COMMENTED;
        }

        return new self(
            author: $author,
            date: isset($message['date']) ? (string) $message['date'] : null,
            patchSet: isset($message['patch_set']) ? (int) $message['patch_set'] : null,
            tag: $tag,
            text: $text,
            event: $event,
            votes: $votes,
            outdatedVotes: $outdated,
            inlineComments: $inline,
            comment: $comment,
            id: isset($message['id']) ? (string) $message['id'] : null,
            workInProgress: self::workInProgress($tag, $ready),
        );
    }

    /**
     * Whether the CI wrote it, not a person, see GerritChangeImporter::CI_ACCOUNTS.
     */
    public function isByCi(): bool
    {
        return self::isCi($this->author);
    }

    private static function isCi(?Person $author): bool
    {
        return in_array($author?->gerritId, GerritChangeImporter::CI_ACCOUNTS, true);
    }

    /**
     * Whether the change is work in progress after the message. Gerrit tags each new patch set by the state it
     * leaves. A comment can mark the change ready without a tag of its own, see READY_LINE.
     */
    private static function workInProgress(?string $tag, bool $ready): ?bool
    {
        return match ($tag) {
            'autogenerated:gerrit:newWipPatchSet', 'autogenerated:gerrit:setWorkInProgress' => true,
            'autogenerated:gerrit:newPatchSet', 'autogenerated:gerrit:setReadyForReview' => false,
            default => $ready ? false : null,
        };
    }

    /**
     * The event the tag of Gerrit names, null for a tag that names none. The text of a new patch set varies, like
     * "Patch Set 1: Cherry Picked from branch main.", the tag does not. A rebase and a new commit message stay what
     * the text tells.
     */
    private static function taggedEvent(?string $tag, ReviewEventEnum $read): ?ReviewEventEnum
    {
        $tagged = match ($tag) {
            'autogenerated:gerrit:newPatchSet', 'autogenerated:gerrit:newWipPatchSet' => ReviewEventEnum::UPLOADED,
            'autogenerated:gerrit:merged' => ReviewEventEnum::MERGED,
            'autogenerated:gerrit:abandon' => ReviewEventEnum::ABANDONED,
            'autogenerated:gerrit:restore' => ReviewEventEnum::RESTORED,
            'autogenerated:gerrit:setWorkInProgress' => ReviewEventEnum::WORK_IN_PROGRESS,
            'autogenerated:gerrit:setReadyForReview' => ReviewEventEnum::READY,
            default => null,
        };
        $refined = [ReviewEventEnum::REBASED, ReviewEventEnum::MESSAGE_UPDATED];
        if (ReviewEventEnum::UPLOADED === $tagged && in_array($read, $refined, true)) {
            return $read;
        }

        return $tagged;
    }

    /**
     * The event of the first line, its votes, and what is left of it as a comment.
     *
     * @return array{ReviewEventEnum, list<array{label: ReviewLabelEnum, vote: ReviewVoteEnum}>, string}
     */
    private static function firstLine(string $line): array
    {
        $line = trim($line);
        if (1 === preg_match('/^Uploaded patch set \d+(?:\.|: (?<rest>.*))?$/', $line, $match)) {
            return [self::patchSetEvent($match['rest'] ?? '', ReviewEventEnum::UPLOADED), [], ''];
        }
        if (1 === preg_match('/^Change has been successfully (merged|cherry-picked)/', $line)) {
            return [ReviewEventEnum::MERGED, [], ''];
        }
        $states = ['Abandoned' => ReviewEventEnum::ABANDONED, 'Restored' => ReviewEventEnum::RESTORED];
        foreach ($states as $start => $event) {
            if (str_starts_with($line, $start)) {
                return [$event, [], trim(substr($line, strlen($start)))];
            }
        }
        if ('Set Ready For Review' === $line) {
            return [ReviewEventEnum::READY, [], ''];
        }
        if ('Set Work In Progress' === $line) {
            return [ReviewEventEnum::WORK_IN_PROGRESS, [], ''];
        }
        if (1 !== preg_match('/^Patch Set \d+:\s*(?<rest>.*)$/', $line, $match)) {
            return [ReviewEventEnum::OTHER, [], $line];
        }

        $votes = [];
        $rest = (string) preg_replace_callback(self::VOTE, static function (array $vote) use (&$votes): string {
            $value = '' === $vote['removed'] ? (int) ($vote['value'] ?? 0) : 0;
            $votes[] = self::vote($vote['label'], $value);

            return '';
        }, $match['rest']);
        $event = self::patchSetEvent(trim($rest), [] === $votes ? ReviewEventEnum::COMMENTED : ReviewEventEnum::VOTED);
        // The note of these events is no comment
        $known = in_array($event, [
            ReviewEventEnum::REBASED,
            ReviewEventEnum::MESSAGE_UPDATED,
            ReviewEventEnum::READY,
            ReviewEventEnum::WORK_IN_PROGRESS,
        ], true);

        return [$event, $votes, $known ? '' : trim($rest)];
    }

    /**
     * What a note on a patch set tells, like "Patch Set 2 was rebased".
     */
    private static function patchSetEvent(string $note, ReviewEventEnum $otherwise): ReviewEventEnum
    {
        return match (true) {
            str_contains($note, 'was rebased') => ReviewEventEnum::REBASED,
            str_contains($note, 'Commit message was updated') => ReviewEventEnum::MESSAGE_UPDATED,
            'Ready For Review' === $note => ReviewEventEnum::READY,
            'Work In Progress' === $note => ReviewEventEnum::WORK_IN_PROGRESS,
            default => $otherwise,
        };
    }

    /**
     * The outdated votes, the number of comments on the code, the rest of the text, and whether it marks the change
     * ready, see READY_LINE.
     *
     * @param list<string> $lines the lines after the first one
     *
     * @return array{list<array{label: ReviewLabelEnum, vote: ReviewVoteEnum}>, int|null, string, bool}
     */
    private static function body(array $lines): array
    {
        $outdated = [];
        $inline = null;
        $rest = [];
        $inOutdated = false;
        $ready = false;
        foreach ($lines as $line) {
            if (self::READY_LINE === trim($line)) {
                $ready = true;
                continue;
            }
            if ('Outdated Votes:' === trim($line)) {
                $inOutdated = true;
                continue;
            }
            $isVote = 1 === preg_match('/^\* (?<label>Code-Review|Verified)(?<value>[+-]\d)/', trim($line), $vote);
            if ($inOutdated && $isVote) {
                $outdated[] = self::vote($vote['label'], (int) $vote['value']);
                continue;
            }
            $inOutdated = false;
            if (1 === preg_match('/^\((?<count>\d+) (?:inline )?comments?\)$/', trim($line), $count)) {
                $inline = (int) $count['count'];
                continue;
            }
            $rest[] = $line;
        }

        return [$outdated, $inline, trim(implode("\n", $rest)), $ready];
    }

    /**
     * @return array{label: ReviewLabelEnum, vote: ReviewVoteEnum}
     */
    private static function vote(string $label, int $value): array
    {
        return [
            'label' => ReviewLabelEnum::from($label),
            'vote' => ReviewVoteEnum::tryFrom($value) ?? ReviewVoteEnum::NONE,
        ];
    }
}
