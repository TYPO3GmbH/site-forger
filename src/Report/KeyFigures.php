<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report;

use App\Document\Change;
use App\Document\Issue;
use App\Elastic\SearchResult;
use App\Enum\IssueSortEnum;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Search\IssueSearch;
use App\Search\IssueSortQuery;
use App\Search\ReviewLists;

/**
 * The numbers and short lists of the dashboard and of the quick stats.
 */
final readonly class KeyFigures
{
    /**
     * The lists show this many entries. Their links lead to all of them.
     */
    public const int LIST_SIZE = 5;

    private const string LAST_30_DAYS = 'now-30d/d';

    private const string FIRST_DAY = '2000-01-01';

    public function __construct(
        private IssueRepository $issues,
        private ChangeRepository $changes,
    ) {
    }

    public function openIssues(): int
    {
        return $this->issues->count(self::filtered([], [self::closedStatus()]));
    }

    public function openedIssues(): int
    {
        return $this->issues->count(self::filtered([
            ['range' => ['created_on' => ['gte' => self::LAST_30_DAYS]]],
        ]));
    }

    public function closedIssues(): int
    {
        return $this->issues->count(self::filtered([
            self::closedStatus(),
            ['range' => ['updated_on' => ['gte' => self::LAST_30_DAYS]]],
        ]));
    }

    /**
     * The changes of the list "Ready for review", with its query, so the number is what the list shows.
     */
    public function readyForReview(): int
    {
        return $this->changes->count(self::filtered([(array) ReviewLists::query(ReviewLists::READY)]));
    }

    /**
     * The changes ready for review that nobody touched for a month: the list "Ready for review" with the filter of
     * waitingFilter(), so the number is what its link shows.
     */
    public function waitingChanges(\DateTimeImmutable $today): int
    {
        $days = MonthOverview::days(self::waitingFilter($today)['updated']);

        return $this->changes->count(self::filtered([
            (array) ReviewLists::query(ReviewLists::READY),
            (array) MonthOverview::rangeQuery($days['from'], $days['to'], 'updated_on'),
        ]));
    }

    /**
     * The filter "Updated" of the review lists for the changes not updated in the last 30 days, the days in UTC
     * as the filter takes them. The date picker needs a first day, too: one before the first change of Gerrit.
     *
     * @return array{updated: array{range: string}}
     */
    public static function waitingFilter(\DateTimeImmutable $today): array
    {
        $lastDay = $today->setTimezone(new \DateTimeZone('UTC'))->modify('-31 days');

        return ['updated' => ['range' => self::FIRST_DAY . ' to ' . $lastDay->format('Y-m-d')]];
    }

    /**
     * The open issues created last, as the search sorts them by "Newest".
     *
     * @return SearchResult<Issue>
     */
    public function newIssues(): SearchResult
    {
        return $this->issues->search(self::latest(IssueSearch::query(''), IssueSortQuery::sort(IssueSortEnum::NEWEST)));
    }

    /**
     * The changes of the list "Ready to merge" updated last.
     *
     * @return SearchResult<Change>
     */
    public function readyToMerge(): SearchResult
    {
        $query = (array) ReviewLists::query(ReviewLists::READY_TO_MERGE);

        return $this->changes->search(self::latest($query, [['updated_on' => 'desc']]));
    }

    /**
     * The request of the first documents of a list, which counts all documents of the list.
     *
     * @param array<string, mixed>       $query
     * @param list<array<string, mixed>> $sort
     *
     * @return array<string, mixed>
     */
    private static function latest(array $query, array $sort): array
    {
        return [
            'query' => $query,
            'sort' => $sort,
            'size' => self::LIST_SIZE,
            'track_total_hits' => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $filter
     * @param list<array<string, mixed>> $mustNot
     *
     * @return array<string, mixed>
     */
    private static function filtered(array $filter, array $mustNot = []): array
    {
        return ['query' => ['bool' => ['filter' => $filter, 'must_not' => $mustNot]]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function closedStatus(): array
    {
        return IssueSearch::closed();
    }
}
