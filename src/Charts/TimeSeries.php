<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts;

/**
 * Turns Elasticsearch date histograms into series that share one time axis.
 *
 * Histograms only cover the range their documents fall into, so two of them rarely have the same buckets:
 * the series are aligned on a list of keys, missing buckets count 0.
 */
final class TimeSeries
{
    public const string MONTH = 'month';
    public const string WEEK = 'week';

    /**
     * The aggregation of a histogram with one bucket per interval, the empty ones included, up to now.
     *
     * @return array<string, mixed>
     */
    public static function histogram(string $field, string $interval, ?\DateTimeImmutable $from = null): array
    {
        $histogram = [
            'field' => $field,
            'calendar_interval' => $interval,
            'format' => self::elasticFormat($interval),
            'min_doc_count' => 0,
            'extended_bounds' => ['max' => 'now/d'],
        ];
        if (null !== $from) {
            // Elasticsearch reads the bound in the format of the keys
            $histogram['extended_bounds']['min'] = self::start($from, $interval)->format(self::phpFormat($interval));
        }

        return ['date_histogram' => $histogram];
    }

    /**
     * The keys of the intervals from the one of $from to the one of $to.
     *
     * @return list<string>
     */
    public static function keys(\DateTimeImmutable $from, \DateTimeImmutable $to, string $interval): array
    {
        $current = self::start($from, $interval);
        $last = self::start($to, $interval);
        $keys = [];
        while ($current <= $last) {
            $keys[] = $current->format(self::phpFormat($interval));
            $current = $current->modify(self::MONTH === $interval ? '+1 month' : '+1 week');
        }

        return $keys;
    }

    /**
     * The document counts of the buckets of a histogram, keyed by their formatted key.
     *
     * @param array<string, mixed>|null $aggregation
     *
     * @return array<string, int>
     */
    public static function counts(?array $aggregation): array
    {
        $counts = [];
        foreach ($aggregation['buckets'] ?? [] as $bucket) {
            $counts[(string) $bucket['key_as_string']] = (int) $bucket['doc_count'];
        }

        return $counts;
    }

    /**
     * The counts for the keys, 0 for keys without a bucket.
     *
     * @param array<string, int> $counts
     * @param list<string>       $keys
     *
     * @return list<int>
     */
    public static function align(array $counts, array $keys): array
    {
        return array_map(static fn (string $key): int => $counts[$key] ?? 0, $keys);
    }

    /**
     * How many documents are open at the end of each interval: all opened up to then minus all closed up to then.
     * Counts before the first key are part of the first value, so the series starts at the real level.
     *
     * @param array<string, int> $opened by key, may reach back further than the keys
     * @param array<string, int> $closed by key, may reach back further than the keys
     * @param list<string>       $keys   ascending
     *
     * @return list<int>
     */
    public static function openAtEnd(array $opened, array $closed, array $keys): array
    {
        $open = 0;
        $values = [];
        $events = [];
        foreach ($opened as $key => $count) {
            $events[$key] = ($events[$key] ?? 0) + $count;
        }
        foreach ($closed as $key => $count) {
            $events[$key] = ($events[$key] ?? 0) - $count;
        }
        ksort($events, SORT_STRING);
        foreach ($keys as $key) {
            foreach ($events as $eventKey => $change) {
                if (strcmp((string) $eventKey, $key) > 0) {
                    break;
                }
                $open += $change;
                unset($events[$eventKey]);
            }
            $values[] = $open;
        }

        return $values;
    }

    /**
     * Running totals, e.g. the issues closed in a year up to each month.
     *
     * @param list<int> $values
     *
     * @return list<int>
     */
    public static function cumulative(array $values): array
    {
        $sum = 0;

        return array_map(static function (int $value) use (&$sum): int {
            $sum += $value;

            return $sum;
        }, $values);
    }

    /**
     * Keys sort like dates: "2026-11" for a month, the Monday "2026-11-16" for a week.
     */
    private static function phpFormat(string $interval): string
    {
        return self::MONTH === $interval ? 'Y-m' : 'Y-m-d';
    }

    private static function elasticFormat(string $interval): string
    {
        return self::MONTH === $interval ? 'yyyy-MM' : 'yyyy-MM-dd';
    }

    private static function start(\DateTimeImmutable $date, string $interval): \DateTimeImmutable
    {
        return match ($interval) {
            self::MONTH => $date->modify('first day of this month')->setTime(0, 0),
            self::WEEK => $date->modify('monday this week')->setTime(0, 0),
            default => throw new \InvalidArgumentException(sprintf('The interval "%s" is not supported.', $interval)),
        };
    }
}
