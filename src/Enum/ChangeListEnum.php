<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

use App\Search\ReviewLists;

/**
 * The review lists the API offers: the parameter "list" of /api/v1/changes. Each is a list of the review pages,
 * see ReviewLists, and has open changes only.
 */
enum ChangeListEnum: string
{
    case READY = 'ready';
    case NEEDS_REVIEW = 'needs-review';
    case NEEDS_VERIFICATION = 'needs-verification';
    case NEEDS_REBASE = 'needs-rebase';
    case NEGATIVE_VOTES = 'negative-votes';
    case REVIEWED_AND_VERIFIED = 'verified';
    case READY_TO_MERGE = 'ready-to-merge';
    case WORK_IN_PROGRESS = 'wip';

    public function label(): string
    {
        return match ($this) {
            self::READY => 'Ready for review',
            self::NEEDS_REVIEW => 'Needs review',
            self::NEEDS_VERIFICATION => 'Needs verification',
            self::NEEDS_REBASE => 'Needs rebase',
            self::NEGATIVE_VOTES => 'Negative votes',
            self::REVIEWED_AND_VERIFIED => 'Reviewed and verified',
            self::READY_TO_MERGE => 'Ready to merge',
            self::WORK_IN_PROGRESS => 'Work in progress',
        };
    }

    /**
     * The name of the list in ReviewLists, the route of its page.
     */
    public function reviewList(): string
    {
        return match ($this) {
            self::READY => ReviewLists::READY,
            self::NEEDS_REVIEW => ReviewLists::NEEDS_REVIEW,
            self::NEEDS_VERIFICATION => ReviewLists::NEEDS_VERIFICATION,
            self::NEEDS_REBASE => ReviewLists::NEEDS_REBASE,
            self::NEGATIVE_VOTES => ReviewLists::NEGATIVE_VOTES,
            self::REVIEWED_AND_VERIFIED => ReviewLists::REVIEWED_AND_VERIFIED,
            self::READY_TO_MERGE => ReviewLists::READY_TO_MERGE,
            self::WORK_IN_PROGRESS => ReviewLists::WORK_IN_PROGRESS,
        };
    }
}
