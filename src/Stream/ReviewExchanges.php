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
use App\Document\StreamEvent;
use App\Elastic\Bucket;
use App\Enum\StreamBetterEnum;
use App\Enum\StreamDurationEnum;
use App\Search\ReviewExchangeQuery;

/**
 * Reads the answers of ReviewExchangeQuery, so tests need no Elasticsearch.
 */
final class ReviewExchanges
{
    /**
     * @param array<string, mixed>|null $now    the aggregations of ReviewExchangeQuery::request()
     * @param array<string, mixed>|null $before the aggregations of ReviewExchangeQuery::countRequest()
     * @param array<string, mixed>|null $waits  the aggregation WAITS of ReviewExchangeQuery::waitRequest()
     * @param list<string>              $keys   the keys of the buckets of time, see TimeSeries::keys()
     */
    public static function of(?array $now, ?array $before, ?array $waits, array $keys): ReviewExchange
    {
        $given = (array) ($now[ReviewExchangeQuery::GIVEN] ?? []);
        $received = (array) ($now[ReviewExchangeQuery::RECEIVED] ?? []);

        return new ReviewExchange(
            $keys,
            (int) ($given['doc_count'] ?? 0),
            (int) ($received['doc_count'] ?? 0),
            // Reviews for others help the project. More reviews got are not good or bad by themselves: they come with
            // more changes. Whether both are in balance, the page says.
            self::trend($given, (array) ($before[ReviewExchangeQuery::GIVEN] ?? []), $keys, StreamBetterEnum::MORE),
            self::trend(
                $received,
                (array) ($before[ReviewExchangeQuery::RECEIVED] ?? []),
                $keys,
                StreamBetterEnum::NEITHER,
            ),
            self::partners($given, 'owner'),
            self::partners($received, 'author'),
            self::waits($waits),
        );
    }

    /**
     * The changes the reviews are on: per bucket of time, in the window and in the one before.
     *
     * @param array<string, mixed> $now
     * @param array<string, mixed> $before
     * @param list<string>         $keys
     */
    private static function trend(array $now, array $before, array $keys, StreamBetterEnum $better): StreamTrend
    {
        $perBucket = [];
        foreach ((array) ($now[ReviewExchangeQuery::OVER_TIME]['buckets'] ?? []) as $bucket) {
            $key = (string) ($bucket['key_as_string'] ?? '');
            $perBucket[$key] = StreamAggregations::cardinality($bucket[ReviewExchangeQuery::CHANGES] ?? null);
        }

        return new StreamTrend(
            StreamAggregations::cardinality($now[ReviewExchangeQuery::CHANGES] ?? null),
            StreamAggregations::cardinality($before[ReviewExchangeQuery::CHANGES] ?? null),
            TimeSeries::align($perBucket, $keys),
            $better,
        );
    }

    /**
     * @param array<string, mixed> $aggregation
     * @param string               $field       the person of the event the partner is: "owner" or "author"
     *
     * @return list<ReviewPartner>
     */
    private static function partners(array $aggregation, string $field): array
    {
        $partners = [];
        foreach (Bucket::listOf($aggregation[ReviewExchangeQuery::PARTNERS] ?? null) as $bucket) {
            $event = StreamEvent::fromDocument($bucket->topHit(ReviewExchangeQuery::LATEST));
            $person = 'owner' === $field ? $event->owner : $event->author;
            if (null !== $person) {
                $changes = StreamAggregations::cardinality($bucket->aggregation(ReviewExchangeQuery::CHANGES));
                $partners[] = new ReviewPartner($person, $changes, $bucket->count);
            }
        }

        return $partners;
    }

    /**
     * @param array<string, mixed>|null $aggregation
     *
     * @return list<OwnWait>
     */
    private static function waits(?array $aggregation): array
    {
        $waits = [];
        foreach (ReviewExchangeQuery::waits() as $value) {
            $bucket = (array) ($aggregation['buckets'][$value] ?? []);
            $own = (array) ($bucket[ReviewExchangeQuery::OWN] ?? []);
            $waits[] = new OwnWait(
                StreamDurationEnum::from($value),
                (int) ($own['doc_count'] ?? 0),
                StreamAggregations::median($own),
                StreamAggregations::median($bucket),
            );
        }

        return $waits;
    }
}
