<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Report;

use App\Elastic\Bucket;
use App\Report\MonthOverview;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class MonthOverviewTest extends TestCase
{
    public function testARowPerYearTheNewestFirstEveryMonthInIt(): void
    {
        $rows = MonthOverview::rows(self::buckets([
            ['key_as_string' => '2025/01/01 00:00:00', 'doc_count' => 3, 'month' => ['buckets' => [
                ['key_as_string' => '2025/03/01 00:00:00', 'doc_count' => 3, 'status' => ['buckets' => [['key' => 'New', 'doc_count' => 2], ['key' => 'Under Review', 'doc_count' => 1]]]],
            ]]],
            ['key_as_string' => '2026/01/01 00:00:00', 'doc_count' => 1, 'month' => ['buckets' => [
                ['key_as_string' => '2026/11/01 00:00:00', 'doc_count' => 1, 'status' => ['buckets' => [['key' => 'New', 'doc_count' => 1]]]],
            ]]],
        ]));

        self::assertSame(['2026', '2025'], array_column($rows, 'year'));
        self::assertCount(12, $rows[1]['months']);
        // The range of the whole year in the filter "Created" of the search
        self::assertSame('2025-01-01 to 2025-12-31', $rows[1]['filter']);
        // The range of the month in the filter "Created" of the search
        self::assertSame(['month' => '03', 'filter' => '2025-03-01 to 2025-03-31', 'total' => 3, 'statuses' => ['New' => 2, 'Under Review' => 1]], $rows[1]['months'][2]);
        // A month without issues counts 0
        self::assertSame(['month' => '01', 'filter' => '2025-01-01 to 2025-01-31', 'total' => 0, 'statuses' => []], $rows[1]['months'][0]);
        self::assertSame(3, MonthOverview::maximum($rows));
    }

    public function testTheRangeOfTheFilterCreatedIncludesBothDays(): void
    {
        self::assertSame(
            ['range' => ['created_on' => ['gte' => '2025/03/01 00:00:00', 'lt' => '2025/07/01 00:00:00']]],
            MonthOverview::rangeQuery('2025-03-01', '2025-06-30'),
        );
        // One side open
        self::assertSame(['range' => ['created_on' => ['lt' => '2026/01/01 00:00:00']]], MonthOverview::rangeQuery('', '2025-12-31'));
        // A month, as in links shared before the datepicker
        self::assertSame(['range' => ['created_on' => ['gte' => '2025/03/01 00:00:00', 'lt' => '2025/04/01 00:00:00']]], MonthOverview::rangeQuery('2025-03', '2025-03'));
        // Another format, or no day of the calendar, counts as empty; nothing at all as no filter
        self::assertSame(['range' => ['created_on' => ['gte' => '2025/03/01 00:00:00']]], MonthOverview::rangeQuery('2025-03-01', '2025-02-30'));
        self::assertNull(MonthOverview::rangeQuery('June', '2025-13'));
        self::assertNull(MonthOverview::rangeQuery(null, null));
        // Another field of dates, as the filter "Updated" of the changes
        self::assertSame(
            ['range' => ['updated_on' => ['gte' => '2026/11/01 00:00:00', 'lt' => '2026/11/21 00:00:00']]],
            MonthOverview::rangeQuery('2026-11-01', '2026-11-20', 'updated_on'),
        );
    }

    public function testTheFilterOfAMonthIsItsFirstAndLastDay(): void
    {
        self::assertSame('2024-02-01 to 2024-02-29', MonthOverview::monthFilter('2024', '02'));
        self::assertSame('2025-12-01 to 2025-12-31', MonthOverview::monthFilter('2025', '12'));
    }

    public function testTheDaysOfTheOneFieldOfTheDatepicker(): void
    {
        self::assertSame(['from' => '2025-03-01', 'to' => '2025-03-31'], MonthOverview::days(['range' => '2025-03-01 to 2025-03-31']));
        // One day clicked alone: it is the first and the last day, so the range always ends
        self::assertSame(['from' => '2025-03-04', 'to' => '2025-03-04'], MonthOverview::days(['range' => '2025-03-04']));
        // Links with from and to still work
        self::assertSame(['from' => '2025-03', 'to' => null], MonthOverview::days(['from' => '2025-03']));
        self::assertSame(['from' => null, 'to' => null], MonthOverview::days(['range' => ' ']));
    }

    public function testTheShadesOnALogarithmicScale(): void
    {
        self::assertSame(0, MonthOverview::shade(0, 370));
        self::assertSame(1, MonthOverview::shade(1, 370));
        // Most months hold few issues, they still differ
        self::assertSame(2, MonthOverview::shade(8, 370));
        self::assertSame(3, MonthOverview::shade(26, 370));
        self::assertSame(MonthOverview::SHADES, MonthOverview::shade(370, 370));
        self::assertSame(MonthOverview::SHADES, MonthOverview::shade(1, 1));
    }

    /**
     * @param list<array<string, mixed>> $buckets
     *
     * @return ArrayCollection<int, Bucket>
     */
    private static function buckets(array $buckets): ArrayCollection
    {
        return new ArrayCollection(array_map(
            static fn (array $bucket): Bucket => Bucket::fromArray($bucket),
            $buckets,
        ));
    }
}
