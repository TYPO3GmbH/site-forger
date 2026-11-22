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
use App\Enum\IssueMarkEnum;
use App\Enum\IssueSortEnum;
use App\Enum\ReviewStatusEnum;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Search\IssueMarkQuery;
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

    /**
     * The merges the dashboard thanks for: two rows of three cards.
     */
    public const int MERGED_SIZE = 6;

    /**
     * The states of an open change the dashboard tells apart, with their names.
     */
    public const array CHANGE_STATES = [
        'ready' => 'Mergeable',
        'conflict' => 'Merge conflict',
        'wip' => 'Work in progress',
    ];
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

    /**
     * The open issues Forge marks as a regression, as the filter "Marked as" finds them, updated last first.
     *
     * @return SearchResult<Issue>
     */
    public function regressions(): SearchResult
    {
        $query = IssueMarkQuery::issues(IssueMarkEnum::REGRESSION);

        return $this->issues->search(self::latest($query, IssueSortQuery::sort(IssueSortEnum::UPDATED)));
    }

    /**
     * The changes of the list "Ready for review", with its query, so the number is what the list shows.
     */
    public function readyForReview(): int
    {
        return $this->changes->count(self::filtered([(array) ReviewLists::query(ReviewLists::READY)]));
    }

    /**
     * The open changes by state, see CHANGE_STATES. A change in progress counts as such, whether it can be merged
     * or not.
     *
     * @return array{ready: int, conflict: int, wip: int}
     */
    public function openChanges(): array
    {
        $workInProgress = ['term' => ['work_in_progress' => true]];
        $conflict = ['term' => ['mergeable' => 'no']];
        $counts = $this->changes->search([
            'query' => ['term' => ['reviewstatus' => ReviewStatusEnum::NEW->value]],
            'size' => 0,
            'aggregations' => [
                'state' => ['filters' => ['filters' => [
                    'ready' => ['bool' => ['must_not' => [$workInProgress, $conflict]]],
                    'conflict' => ['bool' => ['filter' => [$conflict], 'must_not' => [$workInProgress]]],
                    'wip' => $workInProgress,
                ]]],
            ],
        ])->counts('state');

        return [
            'ready' => $counts['ready'] ?? 0,
            'conflict' => $counts['conflict'] ?? 0,
            'wip' => $counts['wip'] ?? 0,
        ];
    }

    /**
     * The changes ready for review that nobody touched for a month, the one waiting longest first: the list "Ready
     * for review" with the filter of waitingFilter() and the order "least-updated", so the number and the rows are
     * what its link shows.
     *
     * @return SearchResult<Change>
     */
    public function waitingChanges(\DateTimeImmutable $today): SearchResult
    {
        $days = MonthOverview::days(self::waitingFilter($today)['updated']);
        $query = ['bool' => ['filter' => [
            (array) ReviewLists::query(ReviewLists::READY),
            (array) MonthOverview::rangeQuery($days['from'], $days['to'], 'updated_on'),
        ]]];

        return $this->changes->search(self::latest($query, [['updated_on' => 'asc']]));
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
     * The changes merged last, each once: a backport has the Change-Id of its original, so the merges of one Change-Id
     * count as one, and the card shows the original, the change created first, with its author. They come in the
     * order of their last merge. Gerrit has no date of the merge in the index: a merged change is not updated after
     * its submit, so its last update is when it was merged.
     *
     * @return SearchResult<Change>
     */
    public function recentlyMerged(): SearchResult
    {
        $merged = ['term' => ['reviewstatus' => ReviewStatusEnum::MERGED->value]];
        $latest = $this->changes->search([
            'query' => $merged,
            'sort' => [['updated_on' => 'desc']],
            'collapse' => ['field' => 'change_id'],
            'size' => self::MERGED_SIZE,
        ]);
        $changeIds = array_values(array_filter(array_map(
            static fn (Change $change): ?string => $change->changeId,
            $latest->getValues(),
        )));
        if ([] === $changeIds) {
            return $latest;
        }
        $originals = $this->changes->search([
            'query' => ['bool' => ['filter' => [$merged, ['terms' => ['change_id' => $changeIds]]]]],
            'sort' => [['created_on' => 'asc']],
            'collapse' => ['field' => 'change_id'],
            'size' => self::MERGED_SIZE,
        ]);
        $byChangeId = [];
        foreach ($originals as $original) {
            $byChangeId[(string) $original->changeId] = $original;
        }

        return SearchResult::ofDocuments(array_values(array_filter(array_map(
            static fn (string $changeId): ?Change => $byChangeId[$changeId] ?? null,
            $changeIds,
        ))));
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
