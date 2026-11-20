<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\IssueSortEnum;

/**
 * The "sort" of a search request in the issue index for the orders of IssueSortEnum.
 */
final class IssueSortQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function sort(IssueSortEnum $sort): array
    {
        return match ($sort) {
            IssueSortEnum::RELEVANCE => [['_score' => 'desc'], ['updated_on' => 'desc']],
            IssueSortEnum::NEWEST => [['created_on' => 'desc']],
            IssueSortEnum::OLDEST => [['created_on' => 'asc']],
            IssueSortEnum::UPDATED => [['updated_on' => 'desc']],
            IssueSortEnum::LEAST_UPDATED => [['updated_on' => 'asc']],
            // The Redmine ids of the priorities go from "Must have" to "-- undefined --", see PriorityEnum.
            // An issue without a priority comes last, and an index without one sorts without an error.
            IssueSortEnum::PRIORITY => [
                ['priority.id' => ['order' => 'asc', 'missing' => '_last', 'unmapped_type' => 'long']],
                ['updated_on' => 'desc'],
            ],
        };
    }
}
