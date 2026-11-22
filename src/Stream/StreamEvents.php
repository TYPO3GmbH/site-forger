<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Document\Change;
use App\Document\Issue;
use App\Document\IssueFieldChange;
use App\Document\IssueJournal;
use App\Document\ReviewMessage;
use App\Document\StreamEvent;
use App\Enum\IssueFieldEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewEventEnum;
use App\Enum\StreamKindEnum;
use App\Enum\StreamSourceEnum;
use App\Import\ForgeIssueImporter;

/**
 * The events of the activity stream that an issue or a change holds: the creation and the notes of a Forge issue,
 * the review log of a Gerrit change.
 *
 * The documents of the indices are the source, so the import and the backfill derive the same events, see
 * StreamWriter. An event names no issue but its own: a relation may point to a private issue.
 */
final class StreamEvents
{
    /**
     * The fields whose values the summary shows. The others name ids, issues or long texts: the summary names them
     * only.
     */
    private const array FIELDS_WITH_VALUES = [
        IssueFieldEnum::STATUS,
        IssueFieldEnum::TRACKER,
        IssueFieldEnum::PRIORITY,
        IssueFieldEnum::DONE,
        IssueFieldEnum::CUSTOM_FIELD,
    ];

    /**
     * @return list<StreamEvent> the oldest first
     */
    public static function ofIssue(Issue $issue): array
    {
        $events = [];
        if (null !== $issue->createdOn) {
            $tracker = (string) ($issue->tracker['name'] ?? '');
            $events[] = new StreamEvent(
                id: self::issueEventId($issue->id, 'created'),
                source: StreamSourceEnum::FORGE,
                kind: StreamKindEnum::CREATED,
                number: $issue->id,
                title: $issue->subject,
                date: $issue->createdOn,
                author: $issue->author,
                summary: '' === $tracker ? [] : [$tracker],
                openDelta: 1,
            );
        }
        foreach ($issue->history() as $position => $journal) {
            $event = self::ofJournal($issue, $journal, $position);
            if (null !== $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return list<StreamEvent> the oldest first
     */
    public static function ofChange(Change $change): array
    {
        $events = [];
        $reviewed = false;
        foreach ($change->messages as $position => $message) {
            $firstReview = !$reviewed && self::isReview($change, $message);
            $reviewed = $reviewed || $firstReview;
            $event = self::ofMessage($change, $message, $position, $firstReview);
            if (null !== $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private static function ofJournal(Issue $issue, IssueJournal $journal, int $position): ?StreamEvent
    {
        if (null === $journal->createdOn) {
            return null;
        }
        $kind = self::journalKind($journal);
        if (null === $kind) {
            return null;
        }

        $date = ForgeIssueImporter::indexDate($journal->createdOn);
        $openDelta = self::journalOpenDelta($journal);

        return new StreamEvent(
            id: self::issueEventId($issue->id, (string) ($journal->id ?? 'p' . $position)),
            source: StreamSourceEnum::FORGE,
            kind: $kind,
            number: $issue->id,
            title: $issue->subject,
            date: $date,
            author: $journal->author,
            bot: $journal->isByBot(),
            summary: array_values(array_unique(array_map(self::fieldSummary(...), $journal->changes))),
            patchSet: $journal->patchSet,
            branch: $journal->branch,
            openDelta: $openDelta,
            duration: -1 === $openDelta ? self::seconds($issue->createdOn, $date) : null,
        );
    }

    /**
     * 1 when the journal opens the issue again, -1 when it closes it: a change of the status between an open and a
     * closed one. A status Forger does not know counts as open.
     */
    private static function journalOpenDelta(IssueJournal $journal): int
    {
        foreach ($journal->changes as $change) {
            $closes = self::isClosed($change->to);
            if (IssueFieldEnum::STATUS === $change->field && self::isClosed($change->from) !== $closes) {
                return $closes ? -1 : 1;
            }
        }

        return 0;
    }

    private static function isClosed(?string $status): bool
    {
        return true === IssueStatusEnum::tryFromLabel((string) $status)?->isClosed();
    }

    /**
     * Null for a journal that tells nothing: no note and no change of a field. Only a change from an open status
     * closes the issue: "Resolved" to "Closed" after a release is no news.
     */
    private static function journalKind(IssueJournal $journal): ?StreamKindEnum
    {
        foreach ($journal->changes as $change) {
            $closing = self::closingKind($change);
            if (null !== $closing) {
                return $closing;
            }
        }
        if (null !== $journal->patchSet) {
            return StreamKindEnum::UPLOADED;
        }
        if ('' !== $journal->notes) {
            return StreamKindEnum::COMMENTED;
        }

        return [] === $journal->changes ? null : StreamKindEnum::CHANGED;
    }

    /**
     * RESOLVED for a change of the status to "Resolved", CLOSED to another closed one, null for any other change.
     */
    private static function closingKind(IssueFieldChange $change): ?StreamKindEnum
    {
        if (
            IssueFieldEnum::STATUS !== $change->field
            || self::isClosed($change->from)
            || !self::isClosed($change->to)
        ) {
            return null;
        }
        $resolved = IssueStatusEnum::RESOLVED === IssueStatusEnum::tryFromLabel((string) $change->to);

        return $resolved ? StreamKindEnum::RESOLVED : StreamKindEnum::CLOSED;
    }

    private static function fieldSummary(IssueFieldChange $change): string
    {
        $name = ucfirst($change->name);
        if (!in_array($change->field, self::FIELDS_WITH_VALUES, true)) {
            return $name;
        }

        return match (true) {
            null === $change->to => $name . ' removed',
            null === $change->from => $name . ': ' . $change->to,
            default => $name . ': ' . $change->from . ' → ' . $change->to,
        };
    }

    private static function ofMessage(
        Change $change,
        ReviewMessage $message,
        int $position,
        bool $firstReview,
    ): ?StreamEvent {
        if (null === $message->date || '' === $message->date) {
            return null;
        }
        [$kind, $summary] = self::messageKind($message);

        return new StreamEvent(
            id: 'gerrit-' . $change->id . '-' . ($message->id ?? 'p' . $position),
            source: StreamSourceEnum::GERRIT,
            kind: $kind,
            number: $change->id,
            title: $change->subject,
            date: $message->date,
            author: $message->author,
            bot: null === $message->author || $message->isByCi(),
            summary: $summary,
            patchSet: $message->patchSet,
            branch: $change->branch,
            openDelta: self::messageOpenDelta($message),
            // The first review ends a wait, so does the merge or the abandon, see StreamDurationEnum
            duration: $firstReview || -1 === self::messageOpenDelta($message)
                ? self::seconds($change->createdOn, $message->date)
                : null,
            firstReview: $firstReview,
        );
    }

    /**
     * Whether a person other than the owner voted or commented: a review. The CI and the owner do not count.
     */
    public static function isReview(Change $change, ReviewMessage $message): bool
    {
        $byOwner = null !== $change->ownerId && $message->author?->gerritId === $change->ownerId;
        $reviews = in_array($message->event, [ReviewEventEnum::VOTED, ReviewEventEnum::COMMENTED], true);

        return $reviews && null !== $message->author && !$message->isByCi() && !$byOwner;
    }

    /**
     * The seconds between two dates of the index, null when one is missing or the end comes first.
     */
    private static function seconds(?string $from, ?string $to): ?int
    {
        $utc = new \DateTimeZone('UTC');
        $start = null === $from ? false : \DateTimeImmutable::createFromFormat('Y/m/d H:i:s', $from, $utc);
        $end = null === $to ? false : \DateTimeImmutable::createFromFormat('Y/m/d H:i:s', $to, $utc);
        if (false === $start || false === $end || $end < $start) {
            return null;
        }

        return $end->getTimestamp() - $start->getTimestamp();
    }

    /**
     * 1 when the message opens the change: its first patch set, or restored. -1 when it closes it: merged or
     * abandoned.
     */
    private static function messageOpenDelta(ReviewMessage $message): int
    {
        return match ($message->event) {
            ReviewEventEnum::UPLOADED => 1 === $message->patchSet ? 1 : 0,
            ReviewEventEnum::RESTORED => 1,
            ReviewEventEnum::MERGED, ReviewEventEnum::ABANDONED => -1,
            default => 0,
        };
    }

    /**
     * @return array{StreamKindEnum, list<string>}
     */
    private static function messageKind(ReviewMessage $message): array
    {
        $votes = array_map(
            static fn (array $vote): string => $vote['label']->value . ' ' . $vote['vote']->label(),
            $message->votes,
        );
        $comments = null === $message->inlineComments ? [] : [self::comments($message->inlineComments)];

        return match ($message->event) {
            ReviewEventEnum::UPLOADED => 1 === $message->patchSet
                ? [StreamKindEnum::CREATED, []]
                : [StreamKindEnum::UPLOADED, []],
            ReviewEventEnum::REBASED => [StreamKindEnum::UPLOADED, ['Rebased']],
            ReviewEventEnum::MESSAGE_UPDATED => [StreamKindEnum::UPLOADED, ['Commit message updated']],
            ReviewEventEnum::VOTED => [StreamKindEnum::VOTED, [...$votes, ...$comments]],
            ReviewEventEnum::COMMENTED => [StreamKindEnum::COMMENTED, [...$votes, ...$comments]],
            ReviewEventEnum::MERGED => [StreamKindEnum::MERGED, []],
            ReviewEventEnum::ABANDONED => [StreamKindEnum::CLOSED, []],
            ReviewEventEnum::RESTORED => [StreamKindEnum::CHANGED, ['Restored']],
            ReviewEventEnum::READY => [StreamKindEnum::CHANGED, ['Ready for review']],
            ReviewEventEnum::WORK_IN_PROGRESS => [StreamKindEnum::CHANGED, ['Work in progress']],
            ReviewEventEnum::OTHER => [StreamKindEnum::CHANGED, []],
        };
    }

    private static function comments(int $count): string
    {
        return 1 === $count ? '1 comment' : $count . ' comments';
    }

    private static function issueEventId(int $issue, string $event): string
    {
        return 'forge-' . $issue . '-' . $event;
    }
}
