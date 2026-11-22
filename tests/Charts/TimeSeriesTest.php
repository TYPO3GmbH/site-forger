<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Charts;

use App\Charts\TimeSeries;
use PHPUnit\Framework\TestCase;

final class TimeSeriesTest extends TestCase
{
    public function testMonthKeysCoverEveryMonthOfTheRange(): void
    {
        self::assertSame(
            ['2025-11', '2025-12', '2026-01', '2026-02'],
            TimeSeries::keys(new \DateTimeImmutable('2025-11-30'), new \DateTimeImmutable('2026-02-01'), TimeSeries::MONTH)
        );
    }

    public function testWeekKeysAreTheirMondays(): void
    {
        // 2026-11-22 is a Sunday, its week starts on the 16th
        self::assertSame(
            ['2026-11-02', '2026-11-09', '2026-11-16'],
            TimeSeries::keys(new \DateTimeImmutable('2026-11-04'), new \DateTimeImmutable('2026-11-22'), TimeSeries::WEEK)
        );
    }

    public function testSeriesAreAlignedOnTheKeys(): void
    {
        $counts = TimeSeries::counts(['buckets' => [
            ['key_as_string' => '2026-01', 'doc_count' => 3],
            ['key_as_string' => '2026-03', 'doc_count' => 1],
        ]]);

        self::assertSame([3, 0, 1, 0], TimeSeries::align($counts, ['2026-01', '2026-02', '2026-03', '2026-04']));
        self::assertSame([], TimeSeries::counts(null));
    }

    public function testCumulative(): void
    {
        self::assertSame([1, 1, 4, 6], TimeSeries::cumulative([1, 0, 3, 2]));
    }

    public function testHistogramHasABucketForEveryIntervalUpToNow(): void
    {
        $histogram = TimeSeries::histogram('updated_on', TimeSeries::WEEK, new \DateTimeImmutable('2026-11-19'))['date_histogram'];

        self::assertSame('week', $histogram['calendar_interval']);
        self::assertSame(0, $histogram['min_doc_count']);
        // In the format of the keys, from the start of the week
        self::assertSame(['max' => 'now/d', 'min' => '2026-11-16'], $histogram['extended_bounds']);
    }
}
