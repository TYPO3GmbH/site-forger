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
use App\Enum\StreamDurationEnum;
use App\Enum\StreamFigureEnum;
use App\Enum\StreamKindEnum;
use App\Enum\StreamSourceEnum;

/**
 * The reviews between a person and the others in a time window: the changes of others the person reviewed, the
 * changes of the person others reviewed, and who the partners were. A change needs several votes and comments, so
 * the balance counts the changes, and the votes and comments beside them. Also how long the changes of
 * the person waited, beside the waits of all changes. A review is a vote or a comment in Gerrit, as
 * StreamFigureEnum::REVIEWS counts it. The events need their owner, see StreamEvents. ReviewExchanges reads the
 * answers.
 */
final class ReviewExchangeQuery
{
    public const string GIVEN = 'given';
    public const string RECEIVED = 'received';
    public const string PARTNERS = 'partners';
    public const string LATEST = 'latest';
    public const string OVER_TIME = 'over_time';
    public const string WAITS = 'waits';
    public const string OWN = 'own';
    public const string CHANGES = 'changes';

    /**
     * The partners each list shows: the closest ones.
     */
    public const int PARTNER_COUNT = 5;

    /**
     * The reviews given and got in the window, per bucket of time, with the partners of each.
     *
     * @return array<string, mixed>
     */
    public static function request(StreamFilter $filter, StreamWindow $window): array
    {
        $overTime = TimeSeries::histogram(
            'date',
            $window->interval(),
            $window->from,
            $window->last(),
            $window->from->getTimezone(),
        );
        $overTime['aggs'] = [self::CHANGES => self::changes()];

        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => self::reviews($window),
            'aggs' => [
                self::GIVEN => [
                    'filter' => self::given($filter),
                    'aggs' => [
                        self::CHANGES => self::changes(),
                        self::OVER_TIME => $overTime,
                        self::PARTNERS => self::partners('owner'),
                    ],
                ],
                self::RECEIVED => [
                    'filter' => self::received($filter),
                    'aggs' => [
                        self::CHANGES => self::changes(),
                        self::OVER_TIME => $overTime,
                        self::PARTNERS => self::partners('author'),
                    ],
                ],
            ],
        ];
    }

    /**
     * The reviews given and got in a window, counts only: the statistics compare with the window before.
     *
     * @return array<string, mixed>
     */
    public static function countRequest(StreamFilter $filter, StreamWindow $window): array
    {
        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => self::reviews($window),
            'aggs' => [
                self::GIVEN => ['filter' => self::given($filter), 'aggs' => [self::CHANGES => self::changes()]],
                self::RECEIVED => ['filter' => self::received($filter), 'aggs' => [self::CHANGES => self::changes()]],
            ],
        ];
    }

    /**
     * How long the changes waited until the events of the window ended the wait: the median of all changes, and in
     * OWN the median of the changes of the person.
     *
     * @return array<string, mixed>
     */
    public static function waitRequest(StreamFilter $filter, StreamWindow $window): array
    {
        $median = ['percentiles' => ['field' => 'duration', 'percents' => [50], 'keyed' => false]];
        $durations = array_intersect_key(StreamInsightsQuery::durations(), array_flip(self::waits()));

        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => ['bool' => ['filter' => [
                $window->query(),
                ['term' => ['source' => StreamSourceEnum::GERRIT->value]],
                ['exists' => ['field' => 'duration']],
            ]]],
            'aggs' => [self::WAITS => [
                'filters' => ['filters' => $durations],
                'aggs' => [
                    StreamInsightsQuery::MEDIAN => $median,
                    self::OWN => [
                        'filter' => StreamQuery::person($filter, 'owner'),
                        'aggs' => [StreamInsightsQuery::MEDIAN => $median],
                    ],
                ],
            ]],
        ];
    }

    /**
     * The waits of a change, by the value of StreamDurationEnum: the waits of the issues have no owner.
     *
     * @return list<string>
     */
    public static function waits(): array
    {
        return [StreamDurationEnum::TIME_TO_FIRST_REVIEW->value, StreamDurationEnum::TIME_TO_CLOSE->value];
    }

    /**
     * The reviews of the window, without the ones of tools.
     *
     * @return array<string, mixed>
     */
    private static function reviews(StreamWindow $window): array
    {
        return ['bool' => ['filter' => [
            $window->query(),
            ['term' => ['source' => StreamFigureEnum::REVIEWS->source()->value]],
            ['terms' => ['kind' => array_map(
                static fn (StreamKindEnum $kind): string => $kind->value,
                StreamFigureEnum::REVIEWS->kinds(),
            )]],
            ['term' => ['bot' => false]],
        ]]];
    }

    /**
     * The reviews of the person on the changes of others.
     *
     * @return array<string, mixed>
     */
    private static function given(StreamFilter $filter): array
    {
        return ['bool' => [
            'filter' => [StreamQuery::person($filter)],
            'must_not' => [StreamQuery::person($filter, 'owner')],
        ]];
    }

    /**
     * The reviews of others on the changes of the person.
     *
     * @return array<string, mixed>
     */
    private static function received(StreamFilter $filter): array
    {
        return ['bool' => [
            'filter' => [StreamQuery::person($filter, 'owner')],
            'must_not' => [StreamQuery::person($filter)],
        ]];
    }

    /**
     * How many changes the reviews are on. Exact up to the threshold, far more than a person reviews in a window.
     *
     * @return array<string, mixed>
     */
    private static function changes(): array
    {
        return ['cardinality' => ['field' => 'number', 'precision_threshold' => 10000]];
    }

    /**
     * The partners with the most changes reviewed, each with the latest event that names them. By name, as the
     * statistics count the people: Forge and Gerrit join under it.
     *
     * @param string $field "owner" for the reviews given, "author" for the ones got
     *
     * @return array<string, mixed>
     */
    private static function partners(string $field): array
    {
        return [
            'terms' => [
                'field' => $field . '.name',
                'size' => self::PARTNER_COUNT,
                'order' => [self::CHANGES => 'desc'],
            ],
            'aggs' => [self::CHANGES => self::changes(), self::LATEST => ['top_hits' => [
                'size' => 1,
                'sort' => [['date' => 'desc']],
                '_source' => [$field, 'date'],
            ]]],
        ];
    }
}
