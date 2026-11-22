<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Charts\TimeSeries;
use App\Elastic\Bucket;
use App\Enum\ReviewStatusEnum;
use App\Enum\StreamBetterEnum;
use App\Enum\StreamFigureEnum;
use App\Enum\StreamSourceEnum;
use App\Enum\StreamSplitEnum;
use App\People\UserDirectory;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Repository\StreamRepository;
use App\Search\ClosedPerYearQuery;
use App\Search\IssueSearch;
use App\Search\LeaderboardQuery;
use App\Search\ReviewExchangeQuery;
use App\Search\ReviewQueueQuery;
use App\Search\StreamFilter;
use App\Search\StreamInsightsQuery;
use App\Search\StreamWindow;

/**
 * Counts the events of the activity stream for the statistics page, in one request. It asks Elasticsearch and
 * keeps the answers for a minute. StreamAggregations reads the answers.
 */
final readonly class StreamStatistics
{
    /**
     * How many years "closedYears" compares, the current one first.
     */
    private const int YEARS = 3;

    public function __construct(
        private StreamRepository $stream,
        private IssueRepository $issues,
        private ChangeRepository $changes,
        private StreamCache $cache,
        private UserDirectory $users,
    ) {
    }

    public function insights(StreamFilter $filter, StreamWindow $window, StreamSplitEnum $split): StreamInsights
    {
        return $this->cache->get(
            'insights',
            [$filter, $window, $split],
            fn (): StreamInsights => $this->computeInsights($filter, $window, $split),
        );
    }

    /**
     * How many events each key figure counts in the window, by the value of the figure: few numbers, one request.
     *
     * @return array<string, int>
     */
    public function counts(StreamFilter $filter, StreamWindow $window): array
    {
        return $this->cache->get('counts', [$filter, $window], fn (): array => $this->computeCounts($filter, $window));
    }

    /**
     * The reviews between the person of the filter and the others, and how long the changes of the person waited.
     * Only the window and the person count of the filter.
     */
    public function exchange(StreamFilter $filter, StreamWindow $window): ReviewExchange
    {
        return $this->cache->get(
            'exchange',
            [$filter, $window],
            fn (): ReviewExchange => $this->computeExchange($filter, $window),
        );
    }

    /**
     * The people with the most activity in the window, by the sort: the leaderboard. The events and the lines of the
     * changes merged, in the window and in the one before. With a source only its events count, and the lines only
     * with Gerrit: they are those of the changes.
     *
     * @return list<LeaderboardRow>
     */
    public function leaderboard(StreamWindow $window, LeaderboardSort $sort, ?StreamSourceEnum $source = null): array
    {
        return $this->cache->get(
            'leaderboard',
            [$window, $sort->value(), $source?->value],
            fn (): array => Leaderboards::rows(
                $this->leaderboardCounts($window, $source),
                $this->leaderboardCounts($window->previous(), $source),
                $sort,
                $this->users->keyOfPerson(...),
            ),
        );
    }

    /**
     * @return array{people: array<string, mixed>, lines: array<string, mixed>|null}
     */
    private function leaderboardCounts(StreamWindow $window, ?StreamSourceEnum $source): array
    {
        $people = $this->stream->search(LeaderboardQuery::request($window, $source));
        $parts = [LeaderboardQuery::FORGE, LeaderboardQuery::GERRIT, LeaderboardQuery::NAMES];

        return [
            'people' => array_combine($parts, array_map($people->aggregation(...), $parts)),
            'lines' => StreamSourceEnum::FORGE === $source ? null : $this->changes
                ->search(LeaderboardQuery::linesRequest($window))
                ->aggregation(LeaderboardQuery::OWNERS),
        ];
    }

    /**
     * The events per weekday and hour of the filter: when people are active.
     */
    public function weekHours(StreamFilter $filter): StreamHeatmap
    {
        return $this->cache->get('week_hours', [$filter], fn (): StreamHeatmap => $this->computeWeekHours($filter));
    }

    /**
     * The events per day of the window of the filter, from its first day on: the calendar of a year.
     *
     * @return list<int>
     */
    public function days(StreamFilter $filter, StreamWindow $year): array
    {
        return $this->cache->get('days', [$filter, $year], fn (): array => $this->computeDays($filter, $year));
    }

    /**
     * The issues and the changes closed in this and the two years before, summed up month by month. They do not
     * depend on a filter or a window.
     *
     * @return array{issues: list<StreamClosedYear>, changes: list<StreamClosedYear>}
     */
    public function closedYears(\DateTimeImmutable $now): array
    {
        $year = (int) $now->format('Y');

        return $this->cache->get('closed_years', [$now->format('Y-m')], fn (): array => [
            'issues' => $this->computeClosedYears($this->issues, ClosedPerYearQuery::issues(...), $year, $now),
            'changes' => $this->computeClosedYears($this->changes, ClosedPerYearQuery::changes(...), $year, $now),
        ]);
    }

    private function computeExchange(StreamFilter $filter, StreamWindow $window): ReviewExchange
    {
        $now = $this->stream->search(ReviewExchangeQuery::request($filter, $window));
        $before = $this->stream->search(ReviewExchangeQuery::countRequest($filter, $window->previous()));
        $waits = $this->stream->search(ReviewExchangeQuery::waitRequest($filter, $window));

        $sides = [ReviewExchangeQuery::GIVEN, ReviewExchangeQuery::RECEIVED];

        return ReviewExchanges::of(
            array_combine($sides, array_map($now->aggregation(...), $sides)),
            array_combine($sides, array_map($before->aggregation(...), $sides)),
            $waits->aggregation(ReviewExchangeQuery::WAITS),
            TimeSeries::keys($window->from, $window->last(), $window->interval()),
        );
    }

    private function computeInsights(StreamFilter $filter, StreamWindow $window, StreamSplitEnum $split): StreamInsights
    {
        $result = $this->stream->search(StreamInsightsQuery::request($filter, $window, $split));
        $previous = $this->stream->search(StreamInsightsQuery::countRequest($filter, $window->previous()));
        $keys = TimeSeries::keys($window->from, $window->last(), $window->interval());
        $overTime = $result->aggregation(StreamInsightsQuery::OVER_TIME);
        $openSources = $this->stream->search(StreamInsightsQuery::openRequest($window, $filter->branch))
            ->aggregation(StreamInsightsQuery::SOURCES);
        $opened = StreamAggregations::opened($openSources);
        $inProgress = StreamAggregations::opened($openSources, StreamInsightsQuery::IN_PROGRESS);

        return new StreamInsights(
            events: $result->total,
            people: new StreamTrend(
                StreamAggregations::cardinality($result->aggregation(StreamInsightsQuery::PEOPLE)),
                StreamAggregations::cardinality($previous->aggregation(StreamInsightsQuery::PEOPLE)),
                StreamAggregations::peoplePerBucket($overTime, $keys),
                StreamBetterEnum::MORE,
            ),
            activeDays: new StreamTrend(
                count(Bucket::listOf($result->aggregation(StreamInsightsQuery::ACTIVE_DAYS))),
                count(Bucket::listOf($previous->aggregation(StreamInsightsQuery::ACTIVE_DAYS))),
                StreamAggregations::activeDaysPerBucket(
                    $result->aggregation(StreamInsightsQuery::ACTIVE_DAYS),
                    $overTime,
                    $window,
                    $keys,
                ),
                StreamBetterEnum::MORE,
            ),
            sources: Bucket::countsOf($result->aggregation(StreamInsightsQuery::SOURCES)),
            kinds: Bucket::countsOf($result->aggregation(StreamInsightsQuery::KINDS)),
            timeline: new StreamTimeline(
                $window->interval(),
                $keys,
                StreamAggregations::series($overTime, $split, $keys),
            ),
            figures: StreamAggregations::figures(
                $result->aggregation(StreamInsightsQuery::FIGURES),
                Bucket::countsOf($previous->aggregation(StreamInsightsQuery::FIGURES)),
                $keys,
            ),
            openIssues: StreamAggregations::openOverTime(
                $this->openIssuesNow(),
                $opened[StreamSourceEnum::FORGE->value] ?? [],
                $keys,
            ),
            openChanges: StreamAggregations::openOverTime(
                $this->openChangesNow($filter->branch),
                $opened[StreamSourceEnum::GERRIT->value] ?? [],
                $keys,
            ),
            openChangesInProgress: StreamAggregations::openOverTime(
                $this->openChangesNow($filter->branch, inProgress: true),
                $inProgress[StreamSourceEnum::GERRIT->value] ?? [],
                $keys,
            ),
            durations: $this->durations($window, $filter->branch),
            reviewQueue: StreamAggregations::reviewQueue(
                $this->changes->search(ReviewQueueQuery::request($filter->branch))
                    ->aggregation(ReviewQueueQuery::AGES),
            ),
            crews: StreamAggregations::crews(
                $result->aggregation(StreamInsightsQuery::CREWS),
                $previous->aggregation(StreamInsightsQuery::CREWS),
            ),
        );
    }

    /**
     * @return array<string, int>
     */
    private function computeCounts(StreamFilter $filter, StreamWindow $window): array
    {
        $result = $this->stream->search(StreamInsightsQuery::countRequest($filter, $window));
        $buckets = Bucket::countsOf($result->aggregation(StreamInsightsQuery::FIGURES));
        $counts = [];
        foreach (StreamFigureEnum::cases() as $figure) {
            $counts[$figure->value] = $buckets[$figure->value] ?? 0;
        }

        return $counts;
    }

    /**
     * @return list<StreamDuration>
     */
    private function durations(StreamWindow $window, ?string $branch): array
    {
        return StreamAggregations::durations(
            $this->stream->search(StreamInsightsQuery::durationRequest($window, $branch))
                ->aggregation(StreamInsightsQuery::DURATIONS),
            $this->stream->search(StreamInsightsQuery::durationRequest($window->previous(), $branch, false))
                ->aggregation(StreamInsightsQuery::DURATIONS),
        );
    }

    private function computeWeekHours(StreamFilter $filter): StreamHeatmap
    {
        $result = $this->stream->search(StreamInsightsQuery::weekHoursRequest($filter));

        return StreamHeatmap::fromBuckets(Bucket::countsOf($result->aggregation(StreamInsightsQuery::WEEK_HOURS)));
    }

    /**
     * @return list<int>
     */
    private function computeDays(StreamFilter $filter, StreamWindow $year): array
    {
        $result = $this->stream->search(StreamInsightsQuery::calendarRequest($filter, $year));

        return TimeSeries::align(
            TimeSeries::counts($result->aggregation(StreamInsightsQuery::DAYS)),
            TimeSeries::keys($year->from, $year->last(), TimeSeries::DAY),
        );
    }

    /**
     * @param \Closure(int): array<string, mixed> $request
     *
     * @return list<StreamClosedYear>
     */
    private function computeClosedYears(
        IssueRepository|ChangeRepository $documents,
        \Closure $request,
        int $year,
        \DateTimeImmutable $now,
    ): array {
        $years = [];
        foreach (range($year, $year - self::YEARS + 1) as $closedIn) {
            $result = $documents->search($request($closedIn));
            $years[] = StreamAggregations::closedYear(
                $result->aggregation(ClosedPerYearQuery::BY_CLOSE),
                $result->aggregation(ClosedPerYearQuery::BY_UPDATE),
                $closedIn,
                $now,
            );
        }

        return $years;
    }

    private function openIssuesNow(): int
    {
        return $this->issues->count(['query' => ['bool' => ['must_not' => [IssueSearch::closed()]]]]);
    }

    private function openChangesNow(?string $branch, bool $inProgress = false): int
    {
        $filters = null === $branch ? [] : [['term' => ['branch' => $branch]]];
        if ($inProgress) {
            $filters[] = ['term' => ['work_in_progress' => true]];
        }

        return $this->changes->count(['query' => ['bool' => [
            'filter' => $filters,
            'must_not' => [['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]]],
        ]]]);
    }
}
