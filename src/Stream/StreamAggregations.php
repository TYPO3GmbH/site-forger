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
use App\Enum\StreamBetterEnum;
use App\Enum\StreamCrewEnum;
use App\Enum\StreamDurationEnum;
use App\Enum\StreamFigureEnum;
use App\Enum\StreamKindEnum;
use App\Enum\StreamQueueAgeEnum;
use App\Enum\StreamSourceEnum;
use App\Enum\StreamSplitEnum;
use App\Enum\StreamWaitBandEnum;
use App\Search\ClosedPerYearQuery;
use App\Search\StreamInsightsQuery;
use App\Search\StreamWindow;

/**
 * Reads the answers of Elasticsearch to the requests of StreamInsightsQuery, ReviewQueueQuery and
 * ClosedPerYearQuery: the figures of the statistics from the aggregations. It asks nothing itself, so tests give it
 * fixed answers. StreamStatistics asks.
 */
final class StreamAggregations
{
    /**
     * The waits of the window with their medians and bands, and the medians of the window before.
     *
     * @param array<string, mixed>|null $now    the aggregation of the window
     * @param array<string, mixed>|null $before the one of the window before
     *
     * @return list<StreamDuration>
     */
    public static function durations(?array $now, ?array $before): array
    {
        $durations = [];
        foreach (StreamDurationEnum::cases() as $duration) {
            $bucket = (array) ($now['buckets'][$duration->value] ?? []);
            $durations[] = new StreamDuration(
                $duration,
                (int) ($bucket['doc_count'] ?? 0),
                self::median($bucket),
                self::median((array) ($before['buckets'][$duration->value] ?? [])),
                self::bands((array) ($bucket[StreamInsightsQuery::BANDS]['buckets'] ?? [])),
            );
        }

        return $durations;
    }

    /**
     * The open changes that wait for a review per band of StreamQueueAgeEnum, also the bands without one.
     *
     * @param array<string, mixed>|null $ages the date range aggregation of ReviewQueueQuery
     *
     * @return array<string, int>
     */
    public static function reviewQueue(?array $ages): array
    {
        $counts = Bucket::countsOf($ages);
        $queue = [];
        foreach (StreamQueueAgeEnum::cases() as $age) {
            $queue[$age->value] = $counts[$age->value] ?? 0;
        }

        return $queue;
    }

    /**
     * The documents closed in a year up to the end of each month, null for the months still to come.
     *
     * @param array<string, mixed>|null $byClose  the aggregation of the documents with a day of the close
     * @param array<string, mixed>|null $byUpdate the one of the documents without, by their last update
     */
    public static function closedYear(
        ?array $byClose,
        ?array $byUpdate,
        int $year,
        \DateTimeImmutable $now,
    ): StreamClosedYear {
        $start = new \DateTimeImmutable($year . '-01-01');
        $keys = TimeSeries::keys($start, $start->modify('last day of december'), TimeSeries::MONTH);
        $closed = TimeSeries::counts($byClose[ClosedPerYearQuery::MONTHS] ?? null);
        $updated = TimeSeries::counts($byUpdate[ClosedPerYearQuery::MONTHS] ?? null);
        $sums = TimeSeries::cumulative(array_map(
            static fn (string $key): int => ($closed[$key] ?? 0) + ($updated[$key] ?? 0),
            $keys,
        ));
        $currentMonth = $now->format('Y-m');

        return new StreamClosedYear($year, array_map(
            static fn (string $key, int $sum): ?int => strcmp($key, $currentMonth) > 0 ? null : $sum,
            $keys,
            $sums,
        ));
    }

    /**
     * @param array<string, mixed>|null $now
     * @param array<string, mixed>|null $before
     *
     * @return list<StreamCrew>
     */
    public static function crews(?array $now, ?array $before): array
    {
        $crews = [];
        foreach (StreamCrewEnum::cases() as $crew) {
            // The busiest first, as the terms aggregation orders them
            $crews[] = new StreamCrew(
                $crew,
                self::crewCounts($now, $crew),
                self::crewCounts($before, $crew),
            );
        }

        return $crews;
    }

    /**
     * @param array<string, mixed>|null $aggregation
     *
     * @return list<int>
     */
    private static function crewCounts(?array $aggregation, StreamCrewEnum $crew): array
    {
        $people = (array) ($aggregation['buckets'][$crew->value][StreamInsightsQuery::PEOPLE] ?? []);

        return array_values(Bucket::countsOf($people));
    }

    /**
     * How many waits took how long, the shortest band first, by the kind of event that ended them.
     *
     * @param array<string, mixed> $buckets of the range aggregation, by the value of StreamWaitBandEnum
     *
     * @return list<StreamWaitBand>
     */
    private static function bands(array $buckets): array
    {
        return array_map(
            static fn (StreamWaitBandEnum $band): StreamWaitBand => new StreamWaitBand(
                $band,
                (int) ($buckets[$band->value]['doc_count'] ?? 0),
                Bucket::countsOf($buckets[$band->value][StreamInsightsQuery::KINDS] ?? null),
            ),
            StreamWaitBandEnum::cases(),
        );
    }

    /**
     * The median of a bucket in seconds, null without events.
     *
     * @param array<string, mixed> $bucket
     */
    public static function median(array $bucket): ?int
    {
        $value = $bucket[StreamInsightsQuery::MEDIAN]['values'][0]['value'] ?? null;

        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /**
     * What opened and closed per channel: per bucket of the window, and after it under the key AFTER. With
     * StreamInsightsQuery::IN_PROGRESS as $sum: what the changes in progress gained and lost.
     *
     * @param array<string, mixed>|null $sources
     *
     * @return array<string, array<string, int>> by channel and the key of the bucket
     */
    public static function opened(?array $sources, string $sum = StreamInsightsQuery::OPENED): array
    {
        $opened = [];
        foreach ((array) ($sources['buckets'] ?? []) as $source) {
            $deltas = [];
            foreach ((array) ($source[StreamInsightsQuery::OVER_TIME]['buckets'] ?? []) as $bucket) {
                $key = (string) ($bucket['key_as_string'] ?? '');
                $deltas[$key] = (int) ($bucket[$sum]['value'] ?? 0);
            }
            $after = $source[StreamInsightsQuery::AFTER][$sum]['value'] ?? 0;
            $deltas[StreamInsightsQuery::AFTER] = (int) $after;
            $opened[(string) ($source['key'] ?? '')] = $deltas;
        }

        return $opened;
    }

    /**
     * What came in and what went out per channel and bucket, see StreamFlow.
     *
     * @param array<string, mixed>|null $sources of StreamInsightsQuery::openRequest()
     * @param list<string>              $keys
     *
     * @return array<string, StreamFlow> by channel
     */
    public static function flows(?array $sources, array $keys): array
    {
        $flows = [];
        foreach ((array) ($sources['buckets'] ?? []) as $source) {
            $incoming = [];
            $outgoing = [];
            foreach ((array) ($source[StreamInsightsQuery::OVER_TIME]['buckets'] ?? []) as $bucket) {
                $key = (string) ($bucket['key_as_string'] ?? '');
                $incoming[$key] = (int) ($bucket[StreamInsightsQuery::INCOMING]['doc_count'] ?? 0);
                $outgoing[$key] = (int) ($bucket[StreamInsightsQuery::OUTGOING]['doc_count'] ?? 0);
            }
            $flows[(string) ($source['key'] ?? '')] = new StreamFlow(
                TimeSeries::align($incoming, $keys),
                TimeSeries::align($outgoing, $keys),
            );
        }

        return $flows;
    }

    /**
     * The open ones at the end of each bucket, counted back from the open ones now: the later buckets and the time
     * after the window undone. Exact now, whatever history the index lacks before the window.
     *
     * @param array<string, int> $deltas what opened and closed, see opened()
     * @param list<string>       $keys
     */
    public static function openOverTime(int $now, array $deltas, array $keys): StreamTrend
    {
        $open = $now - ($deltas[StreamInsightsQuery::AFTER] ?? 0);
        $values = [];
        foreach (array_reverse($keys) as $key) {
            $values[] = $open;
            $open -= $deltas[$key] ?? 0;
        }
        // Below 0 the index lacks notes of the window: it shows none open rather than a count that cannot be
        $values = array_map(static fn (int $value): int => max(0, $value), array_reverse($values));

        $last = $values[count($values) - 1] ?? max(0, $open);

        return new StreamTrend($last, max(0, $open), $values, StreamBetterEnum::LESS);
    }

    /**
     * @param array<string, mixed>|null $aggregation
     */
    public static function cardinality(?array $aggregation): int
    {
        return (int) ($aggregation['value'] ?? 0);
    }

    /**
     * The people of each bucket of time.
     *
     * @param array<string, mixed>|null $overTime
     * @param list<string>              $keys
     *
     * @return list<int>
     */
    public static function peoplePerBucket(?array $overTime, array $keys): array
    {
        $counts = [];
        foreach ((array) ($overTime['buckets'] ?? []) as $bucket) {
            $key = (string) ($bucket['key_as_string'] ?? '');
            $counts[$key] = self::cardinality($bucket[StreamInsightsQuery::PEOPLE] ?? null);
        }

        return TimeSeries::align($counts, $keys);
    }

    /**
     * The active days of each bucket: of a week up to seven, of a day or an hour one when something happened.
     *
     * @param array<string, mixed>|null $activeDays
     * @param array<string, mixed>|null $overTime
     * @param list<string>              $keys
     *
     * @return list<int>
     */
    public static function activeDaysPerBucket(
        ?array $activeDays,
        ?array $overTime,
        StreamWindow $window,
        array $keys,
    ): array {
        $interval = $window->interval();
        if (TimeSeries::WEEK !== $interval && TimeSeries::MONTH !== $interval) {
            $events = TimeSeries::align(TimeSeries::counts($overTime), $keys);

            return array_map(static fn (int $count): int => min(1, $count), $events);
        }
        $counts = [];
        foreach (Bucket::listOf($activeDays) as $day) {
            $date = new \DateTimeImmutable((string) $day->keyAsString);
            $key = TimeSeries::WEEK === $interval
                ? $date->modify('monday this week')->format('Y-m-d')
                : $date->format('Y-m');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return TimeSeries::align($counts, $keys);
    }

    /**
     * The series of the split, each aligned to the keys. A series without events stays, so the legend and the
     * colors do not change with the window.
     *
     * @param array<string, mixed>|null $overTime
     * @param list<string>              $keys
     *
     * @return list<StreamSeries>
     */
    public static function series(?array $overTime, StreamSplitEnum $split, array $keys): array
    {
        if (StreamSplitEnum::TOTAL === $split) {
            $values = TimeSeries::align(TimeSeries::counts($overTime), $keys);

            return [new StreamSeries('Events', 'total', $values)];
        }
        $counts = [];
        foreach ((array) ($overTime['buckets'] ?? []) as $bucket) {
            $key = (string) ($bucket['key_as_string'] ?? '');
            foreach (Bucket::countsOf($bucket[StreamInsightsQuery::SPLIT] ?? null) as $value => $count) {
                $counts[(string) $value][$key] = $count;
            }
        }
        $series = [];
        foreach (self::categories($split) as $value => [$label, $key]) {
            $series[] = new StreamSeries($label, $key, TimeSeries::align($counts[$value] ?? [], $keys));
        }

        return $series;
    }

    /**
     * The label and the key of the color of each value of the split, in their fixed order: a filter never repaints
     * the others.
     *
     * @return array<string, array{string, string}>
     */
    private static function categories(StreamSplitEnum $split): array
    {
        $categories = [];
        foreach (StreamSplitEnum::SOURCE === $split ? StreamSourceEnum::cases() : StreamKindEnum::cases() as $case) {
            $categories[$case->value] = [$case->label(), $case->colorKey()];
        }

        return $categories;
    }

    /**
     * @param array<string, mixed>|null $aggregation the key figures of the window, with their buckets of time
     * @param array<array-key, int>     $before      the key figures of the window before, by their value
     * @param list<string>              $keys
     *
     * @return list<StreamFigure>
     */
    public static function figures(?array $aggregation, array $before, array $keys): array
    {
        $buckets = (array) ($aggregation['buckets'] ?? []);
        $figures = [];
        foreach (StreamFigureEnum::cases() as $figure) {
            $bucket = (array) ($buckets[$figure->value] ?? []);
            $figures[] = new StreamFigure(
                $figure,
                (int) ($bucket['doc_count'] ?? 0),
                (int) ($before[$figure->value] ?? 0),
                TimeSeries::align(TimeSeries::counts($bucket[StreamInsightsQuery::OVER_TIME] ?? null), $keys),
            );
        }

        return $figures;
    }
}
