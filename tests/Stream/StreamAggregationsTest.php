<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Stream;

use App\Enum\StreamBetterEnum;
use App\Enum\StreamCrewEnum;
use App\Enum\StreamDurationEnum;
use App\Enum\StreamFigureEnum;
use App\Enum\StreamIntervalEnum;
use App\Enum\StreamSplitEnum;
use App\Enum\StreamWaitBandEnum;
use App\Search\ClosedPerYearQuery;
use App\Search\StreamInsightsQuery;
use App\Search\StreamWindow;
use App\Stream\StreamAggregations;
use App\Stream\StreamSeries;
use PHPUnit\Framework\TestCase;

/**
 * The figures of the statistics, read from answers as Elasticsearch gives them.
 */
final class StreamAggregationsTest extends TestCase
{
    private const array DAYS = ['2026-11-02', '2026-11-03', '2026-11-04'];

    public function testTheOpenOnesAreCountedBackFromNow(): void
    {
        // 100 open now. 4 opened after the window. In the window: +3, -5, +2.
        $deltas = ['2026-11-02' => 3, '2026-11-03' => -5, '2026-11-04' => 2, StreamInsightsQuery::AFTER => 4];

        $trend = StreamAggregations::openOverTime(100, $deltas, self::DAYS);

        // At the end of each day, the last one first: 96, then 94, then 99. Before the window: 96.
        self::assertSame([99, 94, 96], $trend->values);
        self::assertSame(96, $trend->count);
        self::assertSame(96, $trend->previous);
        self::assertSame(StreamBetterEnum::LESS, $trend->better);
    }

    public function testTheOpenOnesNeverGoBelowZeroWhenTheIndexLacksHistory(): void
    {
        $trend = StreamAggregations::openOverTime(2, ['2026-11-04' => 10], self::DAYS);

        self::assertSame([0, 0, 2], $trend->values);
        self::assertSame(0, $trend->previous);
    }

    public function testWhatOpenedAndClosedIsReadPerChannel(): void
    {
        $opened = StreamAggregations::opened(['buckets' => [[
            'key' => 'forge',
            StreamInsightsQuery::OVER_TIME => ['buckets' => [
                ['key_as_string' => '2026-11-02', StreamInsightsQuery::OPENED => ['value' => 3.0]],
                ['key_as_string' => '2026-11-03', StreamInsightsQuery::OPENED => ['value' => -5.0]],
            ]],
            StreamInsightsQuery::AFTER => [StreamInsightsQuery::OPENED => ['value' => 4.0]],
        ]]]);

        self::assertSame(
            ['forge' => ['2026-11-02' => 3, '2026-11-03' => -5, StreamInsightsQuery::AFTER => 4]],
            $opened,
        );
        self::assertSame([], StreamAggregations::opened(null));
    }

    public function testTheChangesInProgressAreReadFromTheirOwnSum(): void
    {
        $sums = static fn (float $opened, float $inProgress): array => [
            StreamInsightsQuery::OPENED => ['value' => $opened],
            StreamInsightsQuery::IN_PROGRESS => ['value' => $inProgress],
        ];
        $sources = ['buckets' => [[
            'key' => 'gerrit',
            StreamInsightsQuery::OVER_TIME => ['buckets' => [['key_as_string' => '2026-11-02', ...$sums(2.0, 1.0)]]],
            StreamInsightsQuery::AFTER => $sums(-3.0, -1.0),
        ]]];

        self::assertSame(
            ['gerrit' => ['2026-11-02' => 1, StreamInsightsQuery::AFTER => -1]],
            StreamAggregations::opened($sources, StreamInsightsQuery::IN_PROGRESS),
        );
    }

    public function testTheFlowCountsWhatOpenedAndClosedPerChannelAndBucket(): void
    {
        $bucket = static fn (string $key, int $in, int $out): array => [
            'key_as_string' => $key,
            StreamInsightsQuery::INCOMING => ['doc_count' => $in],
            StreamInsightsQuery::OUTGOING => ['doc_count' => $out],
        ];
        $sources = ['buckets' => [[
            'key' => 'forge',
            StreamInsightsQuery::OVER_TIME => ['buckets' => [$bucket('2026-11-03', 4, 7)]],
        ]]];

        $flows = StreamAggregations::flows($sources, ['2026-11-02', '2026-11-03']);

        self::assertSame([0, 4], $flows['forge']->incoming);
        self::assertSame([0, 7], $flows['forge']->outgoing);
        self::assertSame([], StreamAggregations::flows(null, ['2026-11-02']));
    }

    public function testAFigureHasItsCountTheOneBeforeAndItsValuesPerDay(): void
    {
        $figures = StreamAggregations::figures(
            ['buckets' => [StreamFigureEnum::CHANGES_MERGED->value => [
                'doc_count' => 7,
                StreamInsightsQuery::OVER_TIME => ['buckets' => [
                    ['key_as_string' => '2026-11-02', 'doc_count' => 5],
                    ['key_as_string' => '2026-11-04', 'doc_count' => 2],
                ]],
            ]]],
            [StreamFigureEnum::CHANGES_MERGED->value => 9],
            self::DAYS,
        );

        self::assertCount(count(StreamFigureEnum::cases()), $figures);
        $merged = array_find(
            $figures,
            static fn ($figure): bool => StreamFigureEnum::CHANGES_MERGED === $figure->figure,
        );
        self::assertNotNull($merged);
        // A day without a bucket counts 0
        self::assertSame([7, 9, [5, 0, 2]], [$merged->count, $merged->previous, $merged->values]);
        // A figure Elasticsearch does not name stays, with zeros
        self::assertSame([0, 0, [0, 0, 0]], [$figures[0]->count, $figures[0]->previous, $figures[0]->values]);
    }

    public function testTheTotalIsOneSeries(): void
    {
        $overTime = ['buckets' => [['key_as_string' => '2026-11-03', 'doc_count' => 12]]];

        $series = StreamAggregations::series($overTime, StreamSplitEnum::TOTAL, self::DAYS);

        self::assertEquals([new StreamSeries('Events', 'total', [0, 12, 0])], $series);
    }

    public function testASplitKeepsEverySeriesInItsFixedOrder(): void
    {
        $overTime = ['buckets' => [[
            'key_as_string' => '2026-11-03',
            'doc_count' => 12,
            StreamInsightsQuery::SPLIT => ['buckets' => [['key' => 'gerrit', 'doc_count' => 12]]],
        ]]];

        $series = StreamAggregations::series($overTime, StreamSplitEnum::SOURCE, self::DAYS);

        // Forge has no events but stays: the legend and the colors do not change with the window
        self::assertEquals(
            [new StreamSeries('Forge', 'forge', [0, 0, 0]), new StreamSeries('Gerrit', 'gerrit', [0, 12, 0])],
            $series,
        );
    }

    public function testThePeopleOfEachDay(): void
    {
        $overTime = ['buckets' => [
            ['key_as_string' => '2026-11-02', StreamInsightsQuery::PEOPLE => ['value' => 8]],
            ['key_as_string' => '2026-11-04', StreamInsightsQuery::PEOPLE => ['value' => 3]],
        ]];

        self::assertSame([8, 0, 3], StreamAggregations::peoplePerBucket($overTime, self::DAYS));
        self::assertSame(11, StreamAggregations::cardinality(['value' => 11.0]));
        self::assertSame(0, StreamAggregations::cardinality(null));
    }

    public function testADayIsActiveWhenSomethingHappened(): void
    {
        $window = self::window('2026-11-02 to 2026-11-04');
        $overTime = ['buckets' => [
            ['key_as_string' => '2026-11-02', 'doc_count' => 40],
            ['key_as_string' => '2026-11-04', 'doc_count' => 1],
        ]];

        self::assertSame([1, 0, 1], StreamAggregations::activeDaysPerBucket(null, $overTime, $window, self::DAYS));
    }

    public function testAWeekCountsItsActiveDays(): void
    {
        $window = self::window('2026-08-03 to 2026-11-08')->withInterval(StreamIntervalEnum::WEEK);
        self::assertSame('week', $window->interval());
        // Monday and Wednesday of one week, Sunday of the next
        $activeDays = ['buckets' => [
            ['key_as_string' => '2026-08-03', 'doc_count' => 4],
            ['key_as_string' => '2026-08-05', 'doc_count' => 1],
            ['key_as_string' => '2026-08-16', 'doc_count' => 2],
        ]];

        $days = StreamAggregations::activeDaysPerBucket(
            $activeDays,
            null,
            $window,
            ['2026-08-03', '2026-08-10', '2026-08-17'],
        );

        self::assertSame([2, 1, 0], $days);
    }

    public function testAWaitHasItsMedianTheOneBeforeAndItsBands(): void
    {
        $key = StreamDurationEnum::TIME_TO_CLOSE->value;
        $now = ['buckets' => [$key => [
            'doc_count' => 10,
            StreamInsightsQuery::MEDIAN => ['values' => [['key' => 50.0, 'value' => 86399.6]]],
            StreamInsightsQuery::BANDS => ['buckets' => [
                StreamWaitBandEnum::DAY->value => [
                    'doc_count' => 6,
                    StreamInsightsQuery::KINDS => ['buckets' => [['key' => 'merged', 'doc_count' => 6]]],
                ],
                StreamWaitBandEnum::LONGER->value => ['doc_count' => 4],
            ]],
        ]]];
        $before = ['buckets' => [$key => [StreamInsightsQuery::MEDIAN => ['values' => [['value' => 3600]]]]]];

        $durations = StreamAggregations::durations($now, $before);

        self::assertCount(count(StreamDurationEnum::cases()), $durations);
        $toClose = array_find(
            $durations,
            static fn ($duration): bool => StreamDurationEnum::TIME_TO_CLOSE === $duration->duration,
        );
        self::assertNotNull($toClose);
        self::assertSame([10, 86400, 3600], [$toClose->count, $toClose->median, $toClose->previous]);
        // Every band, the shortest first, also the empty ones
        $bands = [];
        foreach ($toClose->bands as $band) {
            $bands[$band->band->value] = [$band->count, $band->kinds];
        }
        self::assertSame(
            [
                'hour' => [0, []],
                'day' => [6, ['merged' => 6]],
                'week' => [0, []],
                'month' => [0, []],
                'longer' => [4, []],
            ],
            $bands,
        );
        // A wait without events has no median
        self::assertNull($durations[0]->median);
        self::assertNull($durations[0]->previous);
    }

    public function testACrewCountsItsPeopleNowAndBefore(): void
    {
        $people = static fn (int ...$counts): array => [StreamInsightsQuery::PEOPLE => ['buckets' => array_map(
            static fn (int $count): array => ['key' => 'Person with ' . $count, 'doc_count' => $count],
            $counts,
        )]];
        $now = ['buckets' => [StreamCrewEnum::MERGING->value => $people(40, 30, 20, 10)]];
        $before = ['buckets' => [StreamCrewEnum::MERGING->value => $people(90, 10)]];

        $crews = StreamAggregations::crews($now, $before);

        self::assertSame([StreamCrewEnum::MERGING, StreamCrewEnum::VOTING], array_column($crews, 'crew'));
        self::assertSame([4, 2, 1], [$crews[0]->people(), $crews[0]->carriers(), $crews[0]->previousCarriers()]);
        // Nobody voted
        self::assertSame([0, 0], [$crews[1]->people(), $crews[1]->carriers()]);
    }

    private static function window(string $days): StreamWindow
    {
        $window = StreamWindow::ofDays($days, new \DateTimeImmutable('2026-11-20 12:00', new \DateTimeZone('UTC')));
        self::assertNotNull($window);

        return $window;
    }

    public function testTheClosedOfAYearSumUpMonthByMonthAndEndWithTheCurrentMonth(): void
    {
        $months = static fn (array $counts): array => [ClosedPerYearQuery::MONTHS => ['buckets' => array_map(
            static fn (string $month, int $count): array => ['key_as_string' => $month, 'doc_count' => $count],
            array_keys($counts),
            $counts,
        )]];

        $year = StreamAggregations::closedYear(
            $months(['2026-01' => 5, '2026-03' => 2]),
            // Of an older import: without the day of the close
            $months(['2026-01' => 1, '2026-02' => 4]),
            2026,
            new \DateTimeImmutable('2026-03-15'),
        );

        self::assertSame(2026, $year->year);
        self::assertSame([6, 10, 12, null, null, null, null, null, null, null, null, null], $year->closed);
    }

    public function testTheReviewQueueHasEveryBand(): void
    {
        $queue = StreamAggregations::reviewQueue(['buckets' => [
            ['key' => 'month', 'doc_count' => 166],
            ['key' => 'week', 'doc_count' => 119],
        ]]);

        self::assertSame(['week' => 119, 'month' => 166, 'quarter' => 0, 'year' => 0, 'longer' => 0], $queue);
    }
}
