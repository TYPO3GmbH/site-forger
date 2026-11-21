<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\ReviewStateEnum;

/**
 * The queries of the states of ReviewStateEnum in the review index.
 */
final class ReviewStateQuery
{
    /**
     * The Gerrit changes in the state. "work_in_progress" only tells the open changes apart.
     *
     * @return array<string, mixed>
     */
    public static function changes(ReviewStateEnum $state): array
    {
        $status = ['term' => ['reviewstatus' => $state->status()->value]];
        $workInProgress = ['term' => ['work_in_progress' => true]];

        return match ($state) {
            ReviewStateEnum::READY => ['bool' => ['filter' => [$status], 'must_not' => [$workInProgress]]],
            ReviewStateEnum::WORK_IN_PROGRESS => ['bool' => ['filter' => [$status, $workInProgress]]],
            ReviewStateEnum::MERGED, ReviewStateEnum::ABANDONED => $status,
        };
    }
}
