<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Import;

use App\Import\ChangeSweep;
use PHPUnit\Framework\TestCase;

final class ChangeSweepTest extends TestCase
{
    public function testTheNextPageStartsAtTheSecondOfTheOldestChange(): void
    {
        $oldest = ChangeSweep::gerritTime('2026-11-19 17:42:30.123000000');

        $next = ChangeSweep::next(null, $oldest, true);

        self::assertSame('2026-11-19 17:42:31', $next?->format('Y-m-d H:i:s'));
    }

    public function testStartsAtTheNewestAgainAfterTheLastPage(): void
    {
        $oldest = ChangeSweep::gerritTime('2012-01-02 08:00:00.000000000');
        $until = ChangeSweep::gerritTime('2012-03-01 00:00:00.000000000');

        self::assertNull(ChangeSweep::next($until, $oldest, false));
        self::assertNull(ChangeSweep::next($until, null, false));
    }

    public function testAPageOfOneSecondDoesNotStandStill(): void
    {
        $until = ChangeSweep::gerritTime('2026-11-19 17:42:31.000000000');
        $oldest = ChangeSweep::gerritTime('2026-11-19 17:42:30.000000000');

        $next = ChangeSweep::next($until, $oldest, true);

        self::assertSame('2026-11-19 17:42:29', $next?->format('Y-m-d H:i:s'));
    }

    public function testTheIndexedChangesOfAPageLeaveOutTheOldestSecond(): void
    {
        $oldest = ChangeSweep::gerritTime('2026-11-19 17:42:30.000000000');
        $until = ChangeSweep::gerritTime('2026-11-20 08:00:00.000000000');

        self::assertSame(
            ['range' => ['updated_on' => ['gt' => '2026/11/19 17:42:30', 'lt' => '2026/11/20 08:00:00']]],
            ChangeSweep::indexed($oldest, $until, true),
        );
    }

    public function testTheFirstPageReachesToTheNewestChange(): void
    {
        $oldest = ChangeSweep::gerritTime('2026-11-19 17:42:30.000000000');

        self::assertSame(
            ['range' => ['updated_on' => ['gt' => '2026/11/19 17:42:30']]],
            ChangeSweep::indexed($oldest, null, true),
        );
    }

    public function testTheLastPageReachesToTheOldestChange(): void
    {
        $oldest = ChangeSweep::gerritTime('2012-01-02 08:00:00.000000000');
        $until = ChangeSweep::gerritTime('2012-03-01 00:00:00.000000000');

        self::assertSame(
            ['range' => ['updated_on' => ['lt' => '2012/03/01 00:00:00']]],
            ChangeSweep::indexed($oldest, $until, false),
        );
    }

    public function testTheComparedChangesAreThoseUpdatedSinceTheNextRun(): void
    {
        self::assertSame(
            ['range' => ['updated_on' => ['gte' => '2020/01/22 11:47:50']]],
            ChangeSweep::compared(ChangeSweep::gerritTime('2020-01-22 11:47:50.000000000')),
        );
    }

    public function testASinglePageTakesTheWholeIndex(): void
    {
        self::assertEquals(['match_all' => new \stdClass()], ChangeSweep::indexed(null, null, false));
    }
}
