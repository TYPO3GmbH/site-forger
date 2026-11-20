<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\ReviewSortEnum;

/**
 * The "sort" of a search request in the review index for the orders of ReviewSortEnum.
 *
 * Changes updated at the same time come smaller first, then those with more patch sets.
 */
final class ReviewSortQuery
{
    private const array TIE_BREAKERS = [['insertions' => 'asc'], ['deletions' => 'asc'], ['patchsets' => 'desc']];

    /**
     * @return list<array<string, mixed>>
     */
    public static function sort(ReviewSortEnum $sort): array
    {
        return match ($sort) {
            ReviewSortEnum::RELEVANCE => [['_score' => 'desc'], ['updated_on' => 'desc']],
            ReviewSortEnum::UPDATED => [['updated_on' => 'desc'], ...self::TIE_BREAKERS],
            ReviewSortEnum::LEAST_UPDATED => [['updated_on' => 'asc'], ...self::TIE_BREAKERS],
            ReviewSortEnum::NEWEST => [['created_on' => 'desc']],
            ReviewSortEnum::OLDEST => [['created_on' => 'asc']],
            // The size is the lines added and removed together, as Gerrit shows it
            ReviewSortEnum::SMALLEST => [
                ['_script' => [
                    'type' => 'number',
                    'order' => 'asc',
                    // Without lines, as imported before them, a change counts as empty instead of failing the search
                    'script' => ['lang' => 'painless', 'source' => ReviewSizeQuery::LINES_SCRIPT],
                ]],
                ['updated_on' => 'desc'],
            ],
            ReviewSortEnum::MOST_PATCHSETS => [['patchsets' => 'desc'], ['updated_on' => 'desc']],
        };
    }
}
