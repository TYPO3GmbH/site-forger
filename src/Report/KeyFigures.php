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
use App\Elastic\Indices;
use App\Enum\IssueSortEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStatusEnum;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Search\IssueSearch;
use App\Search\ReviewLists;
use Elastica\Index;
use Elastica\ResultSet;

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
        private Indices $indices,
    ) {
    }

    public function openIssues(): int
    {
        return $this->count($this->indices->issues(), [], [self::closedStatus()]);
    }

    public function openedIssues(): int
    {
        return $this->count($this->indices->issues(), [
            ['range' => ['created_on' => ['gte' => self::LAST_30_DAYS]]],
        ]);
    }

    public function closedIssues(): int
    {
        return $this->count($this->indices->issues(), [
            self::closedStatus(),
            ['range' => ['updated_on' => ['gte' => self::LAST_30_DAYS]]],
        ]);
    }

    /**
     * The open changes, without the ones in progress.
     */
    public function openChanges(): int
    {
        return $this->count($this->indices->reviews(), [], [
            ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]],
            self::workInProgress(),
        ]);
    }

    /**
     * The changes ready for review that nobody touched for a month.
     */
    public function waitingChanges(): int
    {
        return $this->count($this->indices->reviews(), [
            ['term' => ['reviewstatus' => ReviewStatusEnum::NEW->value]],
            ['range' => ['updated_on' => ['lt' => self::LAST_30_DAYS]]],
        ], [self::workInProgress()]);
    }

    /**
     * The open issues created last, as the search sorts them by "Newest".
     *
     * @return array{documents: list<Issue>, total: int}
     */
    public function newIssues(): array
    {
        $resultSet = $this->latest($this->indices->issues(), IssueSearch::query(''), IssueSortEnum::NEWEST->sort());

        return [
            'documents' => array_values(IssueRepository::fromResultSet($resultSet)->toArray()),
            'total' => $resultSet->getTotalHits(),
        ];
    }

    /**
     * The changes of the list "Ready to merge" updated last.
     *
     * @return array{documents: list<Change>, total: int}
     */
    public function readyToMerge(): array
    {
        $query = (array) ReviewLists::query(ReviewLists::READY_TO_MERGE);
        $resultSet = $this->latest($this->indices->reviews(), $query, [['updated_on' => 'desc']]);

        return [
            'documents' => array_values(ChangeRepository::fromResultSet($resultSet)->toArray()),
            'total' => $resultSet->getTotalHits(),
        ];
    }

    /**
     * The first documents of a list, and how many the list has.
     *
     * @param array<string, mixed>       $query
     * @param list<array<string, mixed>> $sort
     */
    private function latest(Index $index, array $query, array $sort): ResultSet
    {
        return $index->createSearch([
            'query' => $query,
            'sort' => $sort,
            'size' => self::LIST_SIZE,
            'track_total_hits' => true,
        ])->search();
    }

    /**
     * @param list<array<string, mixed>> $filter
     * @param list<array<string, mixed>> $mustNot
     */
    private function count(Index $index, array $filter, array $mustNot = []): int
    {
        return $index->count(['query' => ['bool' => ['filter' => $filter, 'must_not' => $mustNot]]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function closedStatus(): array
    {
        return ['terms' => ['status.name' => IssueStatusEnum::closedLabels()]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function workInProgress(): array
    {
        return ['term' => ['work_in_progress' => true]];
    }
}
