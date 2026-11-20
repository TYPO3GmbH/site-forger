<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Document\Issue;
use App\Enum\IssueStatusEnum;

/**
 * The columns of a sprint board, in their order.
 */
enum BoardColumnEnum
{
    case BLOCKED;
    case OPEN;
    case WIP;
    case REVIEW;
    case DONE;

    /**
     * The name the board shows, also the suffix of the class "status-<label>".
     */
    public function label(): string
    {
        return match ($this) {
            self::BLOCKED => 'BLOCKED',
            self::OPEN => 'Open',
            self::WIP => 'WIP',
            self::REVIEW => 'Review',
            self::DONE => 'Done',
        };
    }

    public static function ofStatus(?IssueStatusEnum $status): self
    {
        return match (true) {
            null === $status => self::OPEN,
            $status->isClosed() => self::DONE,
            IssueStatusEnum::UNDER_REVIEW === $status => self::REVIEW,
            IssueStatusEnum::IN_PROGRESS === $status => self::WIP,
            IssueStatusEnum::ON_HOLD === $status, IssueStatusEnum::NEEDS_FEEDBACK === $status => self::BLOCKED,
            default => self::OPEN,
        };
    }

    public static function ofIssue(Issue $issue): self
    {
        return self::ofStatus(IssueStatusEnum::tryFrom((int) ($issue->status['id'] ?? 0)));
    }
}
