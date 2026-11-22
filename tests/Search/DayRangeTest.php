<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Search\DayRange;
use PHPUnit\Framework\TestCase;

final class DayRangeTest extends TestCase
{
    public function testIncludesTheFirstDayAndLeavesOutTheDayBefore(): void
    {
        $since = new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
        $before = new \DateTimeImmutable('2026-02-01 00:00:00 UTC');

        self::assertSame(
            ['range' => ['created_on' => [
                'gte' => '2026/01/01 00:00:00',
                'lt' => '2026/02/01 00:00:00',
                'format' => 'yyyy/MM/dd HH:mm:ss',
            ]]],
            DayRange::query('created_on', $since, $before),
        );
    }

    public function testHasNoQueryWithoutADay(): void
    {
        self::assertNull(DayRange::query('created_on', null, null));
    }
}
