<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Charts\TimeSeries;
use App\Enum\StreamCrewEnum;
use App\Enum\StreamDurationEnum;
use App\Enum\StreamFigureEnum;
use App\Enum\StreamKindEnum;
use App\Enum\StreamSourceEnum;
use App\Enum\StreamSplitEnum;
use App\Enum\StreamWaitBandEnum;

/**
 * The aggregations of the statistics of the activity stream: the key figures, the events over time, and per weekday
 * and hour. All count in the time zone of the window, the one of the reader.
 */
final class StreamInsightsQuery
{
    public const string OVER_TIME = 'over_time';
    public const string SPLIT = 'split';
    public const string WEEK_HOURS = 'week_hours';
    public const string SOURCES = 'sources';
    public const string KINDS = 'kinds';
    public const string PEOPLE = 'people';
    public const string ACTIVE_DAYS = 'active_days';
    public const string FIGURES = 'figures';
    public const string OPENED = 'opened';
    public const string AFTER = 'after';
    public const string DURATIONS = 'durations';
    public const string DAYS = 'days';
    public const string MEDIAN = 'median';
    public const string CREWS = 'crews';
    public const string BANDS = 'bands';

    /**
     * The key of a bucket of WEEK_HOURS: the weekday (1 for Monday) times 100 plus the hour, like 314 for Wednesday
     * 14:00. Painless counts in the time zone it is given.
     */
    private const string WEEK_HOUR_SCRIPT = 'ZonedDateTime d = doc[\'date\'].value.withZoneSameInstant('
        . 'ZoneId.of(params.zone)); return d.getDayOfWeek().getValue() * 100 + d.getHour();';

    /**
     * @return array<string, mixed>
     */
    public static function request(StreamFilter $filter, StreamWindow $window, StreamSplitEnum $split): array
    {
        $zone = $window->from->getTimezone();
        $overTime = TimeSeries::histogram('date', $window->interval(), $window->from, $window->last(), $zone);
        $overTime['aggs'] = [self::PEOPLE => self::people()];
        if (null !== $split->field()) {
            $overTime['aggs'][self::SPLIT] = ['terms' => ['field' => $split->field(), 'size' => 10]];
        }

        return [
            'size' => 0,
            'track_total_hits' => true,
            'query' => StreamQuery::query($filter),
            'aggs' => [
                self::OVER_TIME => $overTime,
                self::SOURCES => ['terms' => ['field' => 'source', 'size' => 10]],
                self::KINDS => ['terms' => ['field' => 'kind', 'size' => 10]],
                self::PEOPLE => self::people(),
                self::FIGURES => [
                    'filters' => ['filters' => self::figures()],
                    'aggs' => [self::OVER_TIME => TimeSeries::histogram(
                        'date',
                        $window->interval(),
                        $window->from,
                        $window->last(),
                        $zone,
                    )],
                ],
                self::ACTIVE_DAYS => self::activeDays($zone),
                self::CREWS => self::crews(),
            ],
        ];
    }

    /**
     * The key figures, the people and the active days of a window: counts only. The statistics compare with the
     * window before, the dashboard shows the figures of 30 days.
     *
     * @return array<string, mixed>
     */
    public static function countRequest(StreamFilter $filter, StreamWindow $window): array
    {
        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => StreamQuery::query($filter->withWindow($window)),
            'aggs' => [
                self::FIGURES => ['filters' => ['filters' => self::figures()]],
                self::PEOPLE => self::people(),
                self::ACTIVE_DAYS => self::activeDays($window->from->getTimezone()),
                self::CREWS => self::crews(),
            ],
        ];
    }

    /**
     * The people of each crew with their merges or votes, the busiest first. Few people do this work: 1000 names
     * are all of them.
     *
     * @return array<string, mixed>
     */
    private static function crews(): array
    {
        $filters = [];
        foreach (StreamCrewEnum::cases() as $crew) {
            $filters[$crew->value] = ['term' => ['kind' => $crew->kind()->value]];
        }

        return [
            'filters' => ['filters' => $filters],
            'aggs' => [self::PEOPLE => ['terms' => ['field' => 'author.name', 'size' => 1000]]],
        ];
    }

    /**
     * How many people did something, about: counted by name, Forge and Gerrit join under it.
     *
     * @return array<string, mixed>
     */
    private static function people(): array
    {
        return ['cardinality' => ['field' => 'author.name']];
    }

    /**
     * The days of the time zone with at least one event, keyed like the days of TimeSeries.
     *
     * @return array<string, mixed>
     */
    private static function activeDays(\DateTimeZone $zone): array
    {
        return ['date_histogram' => [
            'field' => 'date',
            'calendar_interval' => 'day',
            'format' => 'yyyy-MM-dd',
            'time_zone' => $zone->getName(),
            'min_doc_count' => 1,
        ]];
    }

    /**
     * The events each key figure counts: those of its channel and of any of its kinds, by the value of the figure.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function figures(): array
    {
        $filters = [];
        foreach (StreamFigureEnum::cases() as $figure) {
            $filters[$figure->value] = ['bool' => ['filter' => [
                ['term' => ['source' => $figure->source()->value]],
                ['terms' => ['kind' => array_map(
                    static fn (StreamKindEnum $kind): string => $kind->value,
                    $figure->kinds(),
                )]],
            ]]];
        }

        return $filters;
    }

    /**
     * What opened and closed issues and changes from the start of the window on, per channel: the sum of "open_delta"
     * per bucket of time, and after the window. Counted back from the open ones now, it gives the open ones at the
     * end of each bucket, see StreamStatistics. The events of tools count too: Gerrit closes issues on Forge. The
     * branch counts for the changes only.
     *
     * @return array<string, mixed>
     */
    public static function openRequest(StreamWindow $window, ?string $branch): array
    {
        $zone = $window->from->getTimezone();
        $utc = new \DateTimeZone('UTC');
        $filters = [
            ['range' => ['date' => ['gte' => $window->from->setTimezone($utc)->format('Y/m/d H:i:s')]]],
            ['bool' => ['must_not' => [['term' => ['open_delta' => 0]]]]],
        ];
        if (null !== $branch) {
            $filters[] = ['bool' => ['should' => [
                ['term' => ['source' => StreamSourceEnum::FORGE->value]],
                ['term' => ['branch' => $branch]],
            ], 'minimum_should_match' => 1]];
        }
        $sum = ['sum' => ['field' => 'open_delta']];
        $overTime = TimeSeries::histogram('date', $window->interval(), $window->from, $window->last(), $zone);
        $overTime['aggs'] = [self::OPENED => $sum];

        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => ['bool' => ['filter' => $filters]],
            'aggs' => [self::SOURCES => [
                'terms' => ['field' => 'source', 'size' => 10],
                'aggs' => [
                    self::OVER_TIME => $overTime,
                    self::AFTER => [
                        'filter' => ['range' => ['date' => [
                            'gte' => $window->until->setTimezone($utc)->format('Y/m/d H:i:s'),
                        ]]],
                        'aggs' => [self::OPENED => $sum],
                    ],
                ],
            ]],
        ];
    }

    /**
     * How long work waited until the events of the window ended it: the median per kind of wait, and unless $bands
     * is false how many waits took how long, by the kind of the event that ended them. Like the open ones, it does
     * not depend on the kinds and the person: it is about the issues and changes. The branch counts for the changes.
     *
     * @return array<string, mixed>
     */
    public static function durationRequest(StreamWindow $window, ?string $branch, bool $bands = true): array
    {
        $median = ['percentiles' => ['field' => 'duration', 'percents' => [50], 'keyed' => false]];
        $aggregations = [self::MEDIAN => $median];
        if ($bands) {
            $aggregations[self::BANDS] = [
                'range' => [
                    'field' => 'duration',
                    'keyed' => true,
                    'ranges' => array_map(
                        static fn (StreamWaitBandEnum $band): array => $band->range(),
                        StreamWaitBandEnum::cases(),
                    ),
                ],
                'aggs' => [self::KINDS => ['terms' => ['field' => 'kind', 'size' => 10]]],
            ];
        }
        $filters = [$window->query(), ['exists' => ['field' => 'duration']]];
        if (null !== $branch) {
            $filters[] = ['bool' => ['should' => [
                ['term' => ['source' => StreamSourceEnum::FORGE->value]],
                ['term' => ['branch' => $branch]],
            ], 'minimum_should_match' => 1]];
        }

        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => ['bool' => ['filter' => $filters]],
            'aggs' => [self::DURATIONS => [
                'filters' => ['filters' => self::durations()],
                'aggs' => $aggregations,
            ]],
        ];
    }

    /**
     * The events that end each wait, by the value of StreamDurationEnum.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function durations(): array
    {
        return [
            StreamDurationEnum::TIME_TO_RESOLVE->value => ['term' => ['kind' => StreamKindEnum::RESOLVED->value]],
            StreamDurationEnum::TIME_TO_FIRST_REVIEW->value => ['term' => ['first_review' => true]],
            StreamDurationEnum::TIME_TO_CLOSE->value => ['bool' => ['filter' => [
                ['term' => ['source' => StreamSourceEnum::GERRIT->value]],
                ['terms' => ['kind' => [StreamKindEnum::MERGED->value, StreamKindEnum::CLOSED->value]]],
            ]]],
        ];
    }

    /**
     * The events per day of the year up to the end of the window, for the calendar, see StreamWindow::year().
     *
     * @return array<string, mixed>
     */
    public static function calendarRequest(StreamFilter $filter, StreamWindow $year): array
    {
        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => StreamQuery::query($filter->withWindow($year)),
            'aggs' => [self::DAYS => TimeSeries::histogram(
                'date',
                TimeSeries::DAY,
                $year->from,
                $year->last(),
                $year->from->getTimezone(),
            )],
        ];
    }

    /**
     * The events per weekday and hour of the filter, in the time zone of its window.
     *
     * @return array<string, mixed>
     */
    public static function weekHoursRequest(StreamFilter $filter): array
    {
        $zone = $filter->window?->from->getTimezone() ?? new \DateTimeZone('UTC');

        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => StreamQuery::query($filter),
            'aggs' => [self::WEEK_HOURS => ['terms' => [
                'script' => ['source' => self::WEEK_HOUR_SCRIPT, 'params' => ['zone' => $zone->getName()]],
                'size' => 7 * 24,
            ]]],
        ];
    }
}
