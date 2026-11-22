<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts;

use App\Elastic\Bucket;

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
    public const string DAY = 'day';
    public const string HOUR = 'hour';

    /**
     * The aggregation of a histogram with one bucket per interval, the empty ones included, up to now or $to.
     * With a time zone the intervals start at midnight there, give $from and $to in it, too.
     *
     * @return array<string, mixed>
     */
    public static function histogram(
        string $field,
        string $interval,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?\DateTimeZone $timeZone = null,
    ): array {
        $histogram = [
            'field' => $field,
            'calendar_interval' => $interval,
            'format' => self::elasticFormat($interval),
            'min_doc_count' => 0,
            'extended_bounds' => ['max' => 'now/d'],
        ];
        // Elasticsearch reads the bounds in the format of the keys
        if (null !== $from) {
            $histogram['extended_bounds']['min'] = self::start($from, $interval)->format(self::phpFormat($interval));
        }
        if (null !== $to) {
            $histogram['extended_bounds']['max'] = self::start($to, $interval)->format(self::phpFormat($interval));
        }
        if (null !== $timeZone) {
            $histogram['time_zone'] = $timeZone->getName();
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
            $current = $current->modify('+1 ' . $interval);
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
        foreach (Bucket::listOf($aggregation) as $bucket) {
            $counts[(string) $bucket->keyAsString] = $bucket->count;
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
     * Keys sort like dates: "2026-11" for a month, the Monday "2026-11-16" for a week, "2026-11-16T14" for an hour.
     */
    private static function phpFormat(string $interval): string
    {
        return match ($interval) {
            self::MONTH => 'Y-m',
            self::HOUR => 'Y-m-d\\TH',
            default => 'Y-m-d',
        };
    }

    private static function elasticFormat(string $interval): string
    {
        return match ($interval) {
            self::MONTH => 'yyyy-MM',
            self::HOUR => "yyyy-MM-dd'T'HH",
            default => 'yyyy-MM-dd',
        };
    }

    private static function start(\DateTimeImmutable $date, string $interval): \DateTimeImmutable
    {
        return match ($interval) {
            self::MONTH => $date->modify('first day of this month')->setTime(0, 0),
            self::WEEK => $date->modify('monday this week')->setTime(0, 0),
            self::DAY => $date->setTime(0, 0),
            self::HOUR => $date->setTime((int) $date->format('G'), 0),
            default => throw new \InvalidArgumentException(sprintf('The interval "%s" is not supported.', $interval)),
        };
    }
}
