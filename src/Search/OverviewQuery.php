<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\IssueStatusEnum;
use App\Enum\WaitingForEnum;
use App\Report\MonthOverview;

/**
 * The short lists of My Work: the changes a user can review and the new issues of the interests of the user, see
 * App\Entity\Interest, and the work of the people the user follows that needs help. Each list comes with the
 * filters of the search that show all of it: "filters[<key>][<value>]", see ReviewLists::filterQuery() and
 * IssueListSearch.
 */
final class OverviewQuery
{
    public const int SIZE = 10;

    /**
     * How long a new issue counts as new.
     */
    public const int NEW_DAYS = 14;

    /**
     * How long an issue of a person followed stays new before it counts as unanswered.
     */
    public const int UNANSWERED_DAYS = 7;

    /**
     * The open changes, without work in progress, that the user does not own and did not vote on in the current
     * patch set, the ones updated last first. With paths, only the changes that touch any of them.
     *
     * @param list<int>    $gerritIds the accounts of the user on Gerrit
     * @param list<string> $paths     paths and patterns, see ReviewLists::fileQuery()
     *
     * @return array<string, mixed>
     */
    public static function reviewable(array $gerritIds, array $paths, int $size = self::SIZE): array
    {
        $files = ReviewLists::anyFileQuery($paths);

        return self::changes(
            [WaitingQuery::changes(WaitingForEnum::YOUR_VOTE, $gerritIds), ...(null === $files ? [] : [$files])],
            $size,
        );
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, array<string, string>>
     */
    public static function reviewableFilters(array $paths): array
    {
        $filters = [WaitingQuery::KEY => [self::value(WaitingForEnum::YOUR_VOTE->label()) => 'true']];
        if ([] !== $paths) {
            $filters['file'] = ['path' => implode(', ', $paths), 'match' => ReviewLists::FILES_ANY];
        }

        return $filters;
    }

    /**
     * The issues of the status "New" reported in the last NEW_DAYS days, the newest first. With categories, only
     * the issues of any of them.
     *
     * @param list<string> $categories names of categories on Forge
     *
     * @return array<string, mixed>
     */
    public static function newIssues(array $categories, \DateTimeImmutable $now, int $size = self::SIZE): array
    {
        $filter = [
            self::isNew(),
            (array) MonthOverview::rangeQuery(self::daysAgo($now, self::NEW_DAYS), null),
        ];
        if ([] !== $categories) {
            $filter[] = ['terms' => ['category.name' => $categories]];
        }

        return self::issues($filter, $size);
    }

    /**
     * @param list<string> $categories
     *
     * @return array<string, array<string, string>>
     */
    public static function newIssuesFilters(array $categories, \DateTimeImmutable $now): array
    {
        $filters = [
            'status' => [self::value(IssueStatusEnum::NEW->label()) => 'true'],
            'created' => ['from' => self::daysAgo($now, self::NEW_DAYS)],
        ];
        foreach ($categories as $category) {
            $filters['category'][self::value($category)] = 'true';
        }

        return $filters;
    }

    /**
     * The changes of people that wait for the step, the ones updated last first.
     *
     * @param array<string, mixed> $owners the people as owners, see PeopleFacet::query()
     *
     * @return array<string, mixed>
     */
    public static function peopleChanges(array $owners, WaitingForEnum $step, int $size = self::SIZE): array
    {
        return self::changes([$owners, WaitingQuery::changes($step)], $size);
    }

    /**
     * @param list<string> $keys the keys of the people, see UserDirectory
     *
     * @return array<string, array<string, string>>
     */
    public static function peopleChangesFilters(array $keys, WaitingForEnum $step): array
    {
        return [
            'owner' => self::selected($keys),
            WaitingQuery::KEY => [self::value($step->label()) => 'true'],
        ];
    }

    /**
     * The issues people reported that are still new after UNANSWERED_DAYS days, the newest first: nobody looked
     * at them yet.
     *
     * @param array<string, mixed> $authors the people as authors, see PeopleFacet::query()
     *
     * @return array<string, mixed>
     */
    public static function unansweredIssues(array $authors, \DateTimeImmutable $now, int $size = self::SIZE): array
    {
        return self::issues(
            [$authors, self::isNew(), (array) MonthOverview::rangeQuery(null, self::unansweredUntil($now))],
            $size,
        );
    }

    /**
     * @param list<string> $keys the keys of the people, see UserDirectory
     *
     * @return array<string, array<string, string>>
     */
    public static function unansweredIssuesFilters(array $keys, \DateTimeImmutable $now): array
    {
        return [
            'author' => self::selected($keys),
            'status' => [self::value(IssueStatusEnum::NEW->label()) => 'true'],
            'created' => ['to' => self::unansweredUntil($now)],
        ];
    }

    /**
     * The categories of the issues, the most used first, for the form of an interest.
     *
     * @return array<string, mixed>
     */
    public static function categories(): array
    {
        return [
            'size' => 0,
            'aggs' => ['categories' => ['terms' => ['field' => 'category.name', 'size' => 200]]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $filter
     *
     * @return array<string, mixed>
     */
    private static function changes(array $filter, int $size): array
    {
        return [
            'query' => ['bool' => ['filter' => $filter]],
            'sort' => [['updated_on' => 'desc']],
            'size' => $size,
            'track_total_hits' => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $filter
     *
     * @return array<string, mixed>
     */
    private static function issues(array $filter, int $size): array
    {
        return [
            'query' => ['bool' => ['filter' => $filter]],
            'sort' => [['created_on' => 'desc']],
            'size' => $size,
            'track_total_hits' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function isNew(): array
    {
        return ['term' => ['status.id' => IssueStatusEnum::NEW->value]];
    }

    /**
     * The day a number of days ago, as the filters of dates take it. The search counts whole days.
     */
    private static function daysAgo(\DateTimeImmutable $now, int $days): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->modify(sprintf('-%d days', $days))->format('Y-m-d');
    }

    /**
     * The last day of the issues that count as unanswered: the days of the filter include it.
     */
    private static function unansweredUntil(\DateTimeImmutable $now): string
    {
        return self::daysAgo($now, self::UNANSWERED_DAYS + 1);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private static function selected(array $keys): array
    {
        $selected = [];
        foreach ($keys as $key) {
            $selected[self::value($key)] = 'true';
        }

        return $selected;
    }

    /**
     * A value as the checkboxes of the search send it: with "_" for a space.
     */
    private static function value(string $value): string
    {
        return str_replace(' ', '_', $value);
    }
}
