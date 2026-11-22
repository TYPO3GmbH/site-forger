<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

/**
 * The events of the window per weekday and hour, in the time zone of the reader: when people are active.
 */
final readonly class StreamHeatmap
{
    public const array WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * @param array<int, array<int, int>> $counts by weekday (1 for Monday) and hour (0 to 23)
     */
    public function __construct(
        public array $counts,
    ) {
    }

    /**
     * @param array<int|string, int> $buckets by the key of StreamInsightsQuery::WEEK_HOURS, like 314
     */
    public static function fromBuckets(array $buckets): self
    {
        $counts = [];
        foreach (array_keys(self::WEEKDAYS) as $weekday) {
            $counts[$weekday] = array_fill(0, 24, 0);
        }
        foreach ($buckets as $key => $count) {
            $weekday = intdiv((int) $key, 100);
            $hour = (int) $key % 100;
            if (isset($counts[$weekday][$hour])) {
                $counts[$weekday][$hour] = $count;
            }
        }

        return new self($counts);
    }

    public function count(int $weekday, int $hour): int
    {
        return $this->counts[$weekday][$hour] ?? 0;
    }
}
