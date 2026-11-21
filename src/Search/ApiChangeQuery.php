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
use App\Enum\ChangeStateFilterEnum;
use App\Enum\ReviewSortEnum;

/**
 * The search request of /api/v1/changes: the search words as in the change search, the filters as exact values.
 */
final class ApiChangeQuery
{
    /**
     * The import stores the branch "master" as "main", see GerritChangeImporter::documents().
     */
    private const array BRANCH_ALIASES = ['master' => 'main'];

    /**
     * @param string|null $branch the target branch, like "main" or "13.4"
     *
     * @return array<string, mixed>
     */
    public static function request(
        ListParameters $list,
        ChangeStateFilterEnum $state,
        ?string $branch,
        ReviewSortEnum $sort,
    ): array {
        $bool = ['must' => [ReviewSearch::query($list->query) ?? ['match_all' => new \stdClass()]]];
        $status = $state->status();
        $filter = array_values(array_filter([
            null === $status ? null : ['term' => ['reviewstatus' => $status->value]],
            null === $branch ? null : ['term' => ['branch' => self::BRANCH_ALIASES[$branch] ?? $branch]],
        ]));
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
}
