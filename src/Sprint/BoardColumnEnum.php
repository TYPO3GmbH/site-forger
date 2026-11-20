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
 * The columns of a sprint board, in their order. The value is the name the board shows and the suffix of the
 * class "status-<value>".
 */
enum BoardColumnEnum: string
{
    case BLOCKED = 'BLOCKED';
    case OPEN = 'Open';
    case WIP = 'WIP';
    case REVIEW = 'Review';
    case DONE = 'Done';

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

    /**
     * @template T
     *
     * @param T $value
     *
     * @return array<string, T> by the value of each column, in the order of the board
     */
    public static function each(mixed $value): array
    {
        return array_fill_keys(array_column(self::cases(), 'value'), $value);
    }
}
