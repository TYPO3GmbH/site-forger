<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Api\ChangeFilters;
use App\Api\ListParameters;
use App\Enum\ReviewSortEnum;

/**
 * The search request of /api/v1/changes: the search words as in the change search, a review list, and the filters
 * as exact values.
 */
final class ApiChangeQuery
{
    /**
     * The import stores the branch "master" as "main", see GerritChangeImporter::documents().
     */
    private const array BRANCH_ALIASES = ['master' => 'main'];

    /**
     * @return array<string, mixed>
     */
    public static function request(ListParameters $list, ChangeFilters $filters, ReviewSortEnum $sort): array
    {
        $must = array_values(array_filter([
            ReviewSearch::query($list->query),
            null === $filters->list ? null : ReviewLists::query($filters->list->reviewList()),
        ]));
        $bool = ['must' => [] === $must ? [['match_all' => new \stdClass()]] : $must];
        $filter = self::filters($filters);
        if ([] !== $filter) {
            $bool['filter'] = $filter;
        }

        return [
            'query' => ['bool' => $bool],
            'sort' => ReviewSortQuery::sort($sort),
            'size' => $list->limit,
            'from' => $list->offset,
            'track_total_hits' => true,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function filters(ChangeFilters $filters): array
    {
        $status = $filters->state->status();
        $branch = null === $filters->branch ? null : self::BRANCH_ALIASES[$filters->branch] ?? $filters->branch;

        return array_values(array_filter([
            null === $status ? null : ['term' => ['reviewstatus' => $status->value]],
            null === $branch ? null : ['term' => ['branch' => $branch]],
            null === $filters->path ? null : ReviewLists::fileQuery($filters->path),
            null === $filters->owner ? null : PersonQuery::query($filters->owner, 'owner_id', 'owner'),
            null === $filters->maxLines ? null : ReviewSizeQuery::atMost($filters->maxLines),
            DayRange::query('created_on', $filters->createdSince, $filters->createdBefore),
            DayRange::query('updated_on', $filters->updatedSince, $filters->updatedBefore),
        ]));
    }
}
