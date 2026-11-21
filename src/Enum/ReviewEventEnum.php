<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

/**
 * What a message of the review log of a Gerrit change tells, see App\Document\ReviewMessage. The label completes
 * "<name> <label>" in the log.
 */
enum ReviewEventEnum
{
    case UPLOADED;
    case REBASED;
    case MESSAGE_UPDATED;
    case VOTED;
    case COMMENTED;
    case MERGED;
    case ABANDONED;
    case RESTORED;
    case READY;
    case WORK_IN_PROGRESS;
    case OTHER;

    public function label(): string
    {
        return match ($this) {
            self::UPLOADED => 'uploaded patch set',
            self::REBASED => 'rebased onto patch set',
            self::MESSAGE_UPDATED => 'updated the commit message in patch set',
            self::VOTED => 'voted on patch set',
            self::COMMENTED => 'commented on patch set',
            self::MERGED => 'merged patch set',
            self::ABANDONED => 'abandoned the change',
            self::RESTORED => 'restored the change',
            self::READY => 'marked it ready for review',
            self::WORK_IN_PROGRESS => 'marked it work in progress',
            self::OTHER => 'wrote',
        };
    }

    /**
     * The event of a message Gerrit published comments with: a vote with comments is a comment.
     */
    public function withComments(): self
    {
        return self::VOTED === $this ? self::COMMENTED : $this;
    }

    /**
     * Whether the label ends with the number of the patch set.
     */
    public function namesPatchSet(): bool
    {
        return !in_array($this, [
            self::ABANDONED,
            self::RESTORED,
            self::READY,
            self::WORK_IN_PROGRESS,
            self::OTHER,
        ], true);
    }
}
