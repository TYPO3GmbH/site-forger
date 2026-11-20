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
use App\Enum\ReviewStatusEnum;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Search\IssueSearch;
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
     * The open changes, without the ones in progress.
     */
    public function openChanges(): int
    {
        return $this->changes->count(self::filtered([], [
            ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]],
            self::workInProgress(),
        ]));
    }

    /**
     * The changes ready for review that nobody touched for a month.
     */
    public function waitingChanges(): int
    {
        return $this->changes->count(self::filtered([
            ['term' => ['reviewstatus' => ReviewStatusEnum::NEW->value]],
            ['range' => ['updated_on' => ['lt' => self::LAST_30_DAYS]]],
        ], [self::workInProgress()]));
    }

    /**
     * The open issues created last, as the search sorts them by "Newest".
     *
     * @return SearchResult<Issue>
     */
    public function newIssues(): SearchResult
    {
        return $this->issues->search(self::latest(IssueSearch::query(''), IssueSortEnum::NEWEST->sort()));
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

    /**
     * @return array<string, mixed>
     */
    private static function workInProgress(): array
    {
        return ['term' => ['work_in_progress' => true]];
    }
}
