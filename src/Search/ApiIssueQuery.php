<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Api\IssueFilters;
use App\Api\ListParameters;
use App\Api\PersonFilter;
use App\Enum\IssueSortEnum;
use App\Enum\IssueStateFilterEnum;
use App\Report\CategoryOverview;

/**
 * The search request of /api/v1/issues: the search words as in the issue search, the filters as exact values.
 */
final class ApiIssueQuery
{
    /**
     * The value of the buckets of a breakdown for the issues without one, see breakdown().
     */
    public const string NONE = 'none';

    /**
     * More values than any field of the breakdown has.
     */
    private const int BUCKETS = 200;

    /**
     * @param bool $breakdown whether the request counts how all issues of the search spread, see breakdown()
     *
     * @return array<string, mixed>
     */
    public static function request(
        ListParameters $list,
        IssueFilters $filters,
        IssueSortEnum $sort,
        bool $breakdown = false,
    ): array {
        $bool = ['must' => [IssueSearch::query($list->query, includeClosed: true)]];
        if (IssueStateFilterEnum::OPEN === $filters->state) {
            $bool['must_not'] = [IssueSearch::closed()];
        }
        $filter = self::filters($filters);
        if ([] !== $filter) {
            $bool['filter'] = $filter;
        }
        $request = [
            'query' => ['bool' => $bool],
            'sort' => IssueSortQuery::sort($sort),
            'size' => $list->limit,
            'from' => $list->offset,
            'track_total_hits' => true,
        ];
        if ($breakdown) {
            $request['aggregations'] = self::breakdown();
        }

        return $request;
    }

    /**
     * The aggregations of a breakdown, by the name of the field of IssueBreakdown. The years list only those that
     * have issues, so they need no bounds.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function breakdown(): array
    {
        $year = static fn (string $field): array => ['date_histogram' => [
            'field' => $field,
            'calendar_interval' => 'year',
            'format' => 'yyyy',
            'min_doc_count' => 1,
        ]];

        return [
            'status' => ['terms' => ['field' => 'status.name', 'size' => self::BUCKETS]],
            'tracker' => ['terms' => ['field' => 'tracker.name', 'size' => self::BUCKETS]],
            'category' => ['terms' => [
                'field' => 'category.name',
                'size' => self::BUCKETS,
                'missing' => self::NONE,
            ]],
            'createdYear' => $year('created_on'),
            'updatedYear' => $year('updated_on'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function filters(IssueFilters $filters): array
    {
        $created = DayRange::query('created_on', $filters->createdSince, $filters->createdBefore);
        $updated = DayRange::query('updated_on', $filters->updatedSince, $filters->updatedBefore);

        return array_values(array_filter([
            IssueStateFilterEnum::CLOSED === $filters->state ? IssueSearch::closed() : null,
            null === $filters->tracker ? null : ['term' => ['tracker.name' => $filters->tracker]],
            null === $filters->category ? null : self::category($filters->category),
            null === $filters->author ? null : self::author($filters->author),
            null === $filters->assignee ? null : self::assignee($filters->assignee),
            null === $filters->involving ? null : ['bool' => [
                'should' => [self::author($filters->involving), self::assignee($filters->involving)],
                'minimum_should_match' => 1,
            ]],
            $created,
            $updated,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function author(PersonFilter $person): array
    {
        return PersonQuery::query($person, 'author.id', 'author.name');
    }

    /**
     * @return array<string, mixed>
     */
    private static function assignee(PersonFilter $person): array
    {
        return PersonQuery::query($person, 'assigned_to.id', 'assigned_to.name');
    }

    /**
     * @return array<string, mixed>
     */
    private static function category(string $category): array
    {
        if (self::NONE === $category) {
            return CategoryOverview::withoutCategoryQuery();
        }

        return ['term' => ['category.name' => $category]];
    }
}
