<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

use App\Document\Change;
use App\Document\Issue;

/**
 * Where the first contribution of a new contributor stands, their first issue or their first change, so a mentor
 * sees who could use help. Backed: the API returns the value. See ofIssue() and ofChange() for the order of the
 * checks.
 */
enum StreamNewcomerStateEnum: string
{
    // A first issue
    case WAITING_FOR_ANSWER = 'waiting-for-answer';
    case NEEDS_FEEDBACK = 'needs-feedback';
    case ANSWERED = 'answered';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';

    // A first change
    case WAITING = 'waiting';
    case IN_REVIEW = 'in-review';
    case NEEDS_WORK = 'needs-work';
    case CONFLICT = 'conflict';
    case WORK_IN_PROGRESS = 'wip';
    case MERGED = 'merged';
    case ABANDONED = 'abandoned';

    /**
     * Closed first, then whether the reporter has to answer, then whether a change is in review, then whether
     * somebody took care of it: a status other than "New" or a note of somebody else. Gerrit sets "Under Review" as
     * a tool, so the status tells more than the notes. An issue Forger has not imported yet waits.
     */
    public static function ofIssue(?Issue $issue, bool $answered): self
    {
        $status = $issue?->statusEnum();

        return match (true) {
            IssueStatusEnum::RESOLVED === $status => self::RESOLVED,
            true === $status?->isClosed() => self::CLOSED,
            IssueStatusEnum::NEEDS_FEEDBACK === $status => self::NEEDS_FEEDBACK,
            IssueStatusEnum::UNDER_REVIEW === $status => self::IN_REVIEW,
            $answered, null !== $status && IssueStatusEnum::NEW !== $status => self::ANSWERED,
            default => self::WAITING_FOR_ANSWER,
        };
    }

    /**
     * Closed first, then what the owner has to do, then whether somebody else reviewed it. A change Forger has not
     * imported yet waits.
     */
    public static function ofChange(?Change $change, bool $reviewed): self
    {
        $state = $change?->state();

        return match (true) {
            ReviewStateEnum::MERGED === $state => self::MERGED,
            ReviewStateEnum::ABANDONED === $state => self::ABANDONED,
            ReviewStateEnum::WORK_IN_PROGRESS === $state => self::WORK_IN_PROGRESS,
            true === $change?->isConflict() => self::CONFLICT,
            null !== $change && self::isRejected($change) => self::NEEDS_WORK,
            $reviewed => self::IN_REVIEW,
            default => self::WAITING,
        };
    }

    /**
     * A -1 or -2 of a review, or the CI failed: the owner has to push a new patch set.
     */
    private static function isRejected(Change $change): bool
    {
        foreach (ReviewLabelEnum::cases() as $label) {
            if (ReviewLabelStateEnum::REJECT === $change->labelState($label)) {
                return true;
            }
        }

        return false;
    }

    public function label(): string
    {
        return match ($this) {
            self::WAITING_FOR_ANSWER => 'Waiting for a first answer',
            self::NEEDS_FEEDBACK => 'Needs feedback',
            self::ANSWERED => 'Answered',
            self::RESOLVED => 'Resolved',
            self::CLOSED => 'Closed or rejected',
            self::WAITING => 'Waiting for a first review',
            self::IN_REVIEW => 'In review',
            self::NEEDS_WORK => 'Needs a new patch set',
            self::CONFLICT => 'Merge conflict',
            self::WORK_IN_PROGRESS => 'Work in progress',
            self::MERGED => 'Merged',
            self::ABANDONED => 'Abandoned',
        };
    }

    /**
     * The key of the state for styling: "status-pill-<severity>" of assets/css/module/_status.scss.
     */
    public function severity(): string
    {
        return match ($this) {
            self::WAITING_FOR_ANSWER, self::WAITING => 'under-review',
            self::NEEDS_FEEDBACK => 'needs-feedback',
            self::ANSWERED => 'accepted',
            self::RESOLVED => 'resolved',
            self::CLOSED => 'closed',
            self::IN_REVIEW => 'ready',
            self::NEEDS_WORK => 'needs-feedback',
            self::CONFLICT => 'conflict',
            self::WORK_IN_PROGRESS => 'wip',
            self::MERGED => 'merged',
            self::ABANDONED => 'abandoned',
        };
    }

    /**
     * Whether a mentor could help now: the issue or the change waits for an answer, a review or its author.
     */
    public function needsHelp(): bool
    {
        return in_array(
            $this,
            [self::WAITING_FOR_ANSWER, self::NEEDS_FEEDBACK, self::WAITING, self::NEEDS_WORK, self::CONFLICT],
            true,
        );
    }
}
