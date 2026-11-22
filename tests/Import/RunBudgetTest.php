<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Import;

use App\Import\RunBudget;
use PHPUnit\Framework\TestCase;

final class RunBudgetTest extends TestCase
{
    public function testTheTimeIsLeftUntilTheSecondsOfTheRunAreOver(): void
    {
        $now = 1000.0;
        $budget = new RunBudget(240, static function () use (&$now): float {
            return $now;
        });

        self::assertTrue($budget->hasTimeLeft());
        $now = 1239.9;
        self::assertTrue($budget->hasTimeLeft());
        $now = 1240.0;
        self::assertFalse($budget->hasTimeLeft());
    }

    public function testItHandsOutBatchesUntilTheTimeIsOver(): void
    {
        $now = 0.0;
        $budget = new RunBudget(10, static function () use (&$now): float {
            return $now;
        });
        $batches = [];
        // Each batch takes four seconds: the third starts at eight, the fourth would start at twelve
        $work = static function (array $batch) use (&$batches, &$now): void {
            $batches[] = $batch;
            $now += 4.0;
        };
        $done = $budget->each([1, 2, 3, 4, 5, 6, 7, 8, 9], 2, $work);

        self::assertSame([[1, 2], [3, 4], [5, 6]], $batches);
        self::assertSame(6, $done);
    }

    public function testItHandsOutNothingWhenTheTimeIsOverAlready(): void
    {
        $now = 0.0;
        $budget = new RunBudget(10, static function () use (&$now): float {
            return $now;
        });
        $now = 11.0;

        self::assertSame(0, $budget->each([1, 2, 3], 2, static fn (array $batch) => self::fail('No time left')));
    }

    public function testItHandsOutAllItemsWhenTheTimeLasts(): void
    {
        $budget = new RunBudget(10, static fn (): float => 0.0);
        $seen = [];
        $done = $budget->each([1, 2, 3], 2, static function (array $batch) use (&$seen): void {
            $seen = [...$seen, ...$batch];
        });

        self::assertSame([3, [1, 2, 3]], [$done, $seen]);
    }
}
