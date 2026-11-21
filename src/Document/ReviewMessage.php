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
     * @param string|null                                               $tag            what Gerrit wrote it for
     * @param list<array{label: ReviewLabelEnum, vote: ReviewVoteEnum}> $votes          the votes it gives, NONE for a
     *                                                                                  removed one
     * @param list<array{label: ReviewLabelEnum, vote: ReviewVoteEnum}> $outdatedVotes  the votes a new patch set made
     *                                                                                  outdated
     * @param int|null                                                  $inlineComments the comments on the code
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
        [$outdated, $inline, $body] = self::body($lines);
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
            tag: isset($message['tag']) ? (string) $message['tag'] : null,
            text: $text,
            event: $event,
            votes: $votes,
            outdatedVotes: $outdated,
            inlineComments: $inline,
            comment: $comment,
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
     * The outdated votes, the number of comments on the code, and the rest of the text.
     *
     * @param list<string> $lines the lines after the first one
     *
     * @return array{list<array{label: ReviewLabelEnum, vote: ReviewVoteEnum}>, int|null, string}
     */
    private static function body(array $lines): array
    {
        $outdated = [];
        $inline = null;
        $rest = [];
        $inOutdated = false;
        foreach ($lines as $line) {
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

        return [$outdated, $inline, trim(implode("\n", $rest))];
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
