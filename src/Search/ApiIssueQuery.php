<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Api\ListParameters;
use App\Enum\IssueSortEnum;
use App\Enum\IssueStateFilterEnum;

/**
 * The search request of /api/v1/issues: the search words as in the issue search, the filters as exact values.
 */
final class ApiIssueQuery
{
    /**
     * @param string|null $tracker  the name of a tracker, like "Bug"
     * @param string|null $category the name of a category, like "Backend User Interface"
     *
     * @return array<string, mixed>
     */
    public static function request(
        ListParameters $list,
        IssueStateFilterEnum $state,
        ?string $tracker,
        ?string $category,
        IssueSortEnum $sort,
    ): array {
        $bool = ['must' => [IssueSearch::query($list->query, includeClosed: true)]];
        if (IssueStateFilterEnum::OPEN === $state) {
            $bool['must_not'] = [IssueSearch::closed()];
        }
        $filter = array_values(array_filter([
            IssueStateFilterEnum::CLOSED === $state ? IssueSearch::closed() : null,
            null === $tracker ? null : ['term' => ['tracker.name' => $tracker]],
            null === $category ? null : ['term' => ['category.name' => $category]],
        ]));
        if ([] !== $filter) {
            $bool['filter'] = $filter;
        }

        return [
            'query' => ['bool' => $bool],
            'sort' => IssueSortQuery::sort($sort),
            'size' => $list->limit,
            'from' => $list->offset,
            'track_total_hits' => true,
        ];
    }
}
