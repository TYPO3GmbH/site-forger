<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\IssueReconciliation;
use PHPUnit\Framework\TestCase;

final class IssueReconciliationTest extends TestCase
{
    public function testAlikeNumbersCostNothingMore(): void
    {
        $counts = 0;
        $count = static function () use (&$counts): int {
            ++$counts;

            return 0;
        };

        self::assertSame([], IssueReconciliation::ranges(1, 100000, 60000, 60000, $count, $count, 15));
        self::assertSame(0, $counts);
    }

    public function testFindsTheRangeOfAMissingIssue(): void
    {
        // Forge has every number from 1 to 10000, the index lacks 7777
        $forge = static fn (int $from, int $to): int => $to - $from + 1;
        $index = static fn (int $from, int $to): int => $to - $from + 1 - (7777 >= $from && 7777 <= $to ? 1 : 0);
        $forgeCounts = 0;
        $countForge = static function (int $from, int $to) use ($forge, &$forgeCounts): int {
            ++$forgeCounts;

            return $forge($from, $to);
        };

        $ranges = IssueReconciliation::ranges(1, 10000, 10000, 9999, $countForge, $index, 15);

        self::assertCount(1, $ranges);
        [$from, $to] = $ranges[0];
        self::assertTrue($from <= 7777 && 7777 <= $to);
        self::assertLessThanOrEqual(IssueReconciliation::LISTED, $to - $from + 1);
        // Halved until a page: log2(10000 / 100), rounded up
        self::assertSame(7, $forgeCounts);
    }

    public function testFindsIssuesGoneFromForgeAndTheNewestFirst(): void
    {
        // Gone from Forge: 150 and 9150; the index has every number
        $gone = [150, 9150];
        $forge = static fn (int $from, int $to): int => $to - $from + 1 - count(array_filter($gone, static fn (int $id): bool => $id >= $from && $id <= $to));
        $index = static fn (int $from, int $to): int => $to - $from + 1;

        $ranges = IssueReconciliation::ranges(1, 10000, 9998, 10000, $forge, $index, 30);

        self::assertCount(2, $ranges);
        self::assertTrue($ranges[0][0] <= 9150 && 9150 <= $ranges[0][1], 'The higher numbers first');
        self::assertTrue($ranges[1][0] <= 150 && 150 <= $ranges[1][1]);
    }

    public function testStopsAtTheBudgetAndKeepsWhatItFound(): void
    {
        $forge = static fn (int $from, int $to): int => $to - $from + 1;
        $index = static fn (int $from, int $to): int => 0;

        // An empty index: every range differs. Few counts find few ranges, the next runs find more
        $ranges = IssueReconciliation::ranges(1, 10000, 10000, 0, $forge, $index, 8);

        self::assertNotSame([], $ranges);
        self::assertLessThan(10, count($ranges));
        foreach ($ranges as [$from, $to]) {
            self::assertLessThanOrEqual(IssueReconciliation::LISTED, $to - $from + 1);
        }
    }
}
