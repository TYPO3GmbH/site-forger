<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Charts\TimeSeries;
use App\Enum\StreamIntervalEnum;
use App\Enum\StreamRangeEnum;
use App\Search\StreamWindow;
use PHPUnit\Framework\TestCase;

final class StreamWindowTest extends TestCase
{
    public function testNowEndsAtTheFullMinuteInTheGivenTimeZone(): void
    {
        $now = StreamWindow::now(new \DateTimeZone('Europe/Berlin'));

        self::assertSame('00', $now->format('s'));
        self::assertSame('Europe/Berlin', $now->getTimezone()->getName());
        self::assertLessThan(60, time() - $now->getTimestamp());
    }

    public function testARangeStartsAtMidnightInTheTimeZoneOfTheReader(): void
    {
        $now = new \DateTimeImmutable('2026-11-21 22:30:00', new \DateTimeZone('Europe/Berlin'));

        $window = StreamWindow::ofRange(StreamRangeEnum::WEEK, $now);

        self::assertSame('2026/11/14 23:00:00', $window->query()['range']['date']['gte']);
        self::assertArrayNotHasKey('lt', $window->query()['range']['date']);
        self::assertTrue($window->isOpen);
        self::assertSame(TimeSeries::DAY, $window->interval());
    }

    public function testDaysInThePastEndAfterTheLastOne(): void
    {
        $now = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('UTC'));

        $window = StreamWindow::ofDays('2026-11-08 to 2026-11-02', $now);

        self::assertNotNull($window);
        self::assertFalse($window->isOpen);
        self::assertSame(
            ['gte' => '2026/11/02 00:00:00', 'lt' => '2026/11/09 00:00:00'],
            $window->query()['range']['date'],
        );
        self::assertSame('2026-11-02 to 2026-11-08', $window->days());
    }

    public function testTheWindowBeforeEndsAtTheSameTimeOfDay(): void
    {
        $now = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('UTC'));

        $window = StreamWindow::ofRange(StreamRangeEnum::MONTH, $now);
        $previous = $window->previous();

        self::assertSame(30, $window->wholeDays());
        self::assertSame(
            ['gte' => '2026/09/23 00:00:00', 'lt' => '2026/10/22 12:00:00'],
            $previous->query()['range']['date'],
        );
    }

    public function testAWeekJustBegunComparesWithAWeekAsFar(): void
    {
        $now = new \DateTimeImmutable('2026-11-22 00:05:00', new \DateTimeZone('Europe/Berlin'));

        $window = StreamWindow::ofRange(StreamRangeEnum::WEEK, $now);

        self::assertSame(7, $window->wholeDays());
        self::assertSame('2026-11-09 00:00', $window->previous()->from->format('Y-m-d H:i'));
        self::assertSame('2026-11-15 00:05', $window->previous()->until->format('Y-m-d H:i'));
    }

    public function testADayOfTwentyFiveHoursIsOneDay(): void
    {
        $now = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('Europe/Berlin'));

        $window = StreamWindow::ofDays('2026-10-25', $now);

        self::assertSame(1, $window?->wholeDays());
    }

    public function testTheBarsGetLongerWithTheWindow(): void
    {
        $now = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame(TimeSeries::HOUR, StreamWindow::ofDays('2026-11-20', $now)?->interval());
        self::assertSame(TimeSeries::WEEK, StreamWindow::ofRange(StreamRangeEnum::HALF_YEAR, $now)->interval());
        // Twenty years of weeks are too many bars
        $years = StreamWindow::ofDays('2004-01-01 to 2026-11-20', $now);
        self::assertNotNull($years);
        self::assertSame(TimeSeries::MONTH, $years->interval());
        self::assertNotContains(StreamIntervalEnum::WEEK, $years->intervals());
    }

    public function testATimeInTheFutureIsNoWindow(): void
    {
        $now = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('UTC'));

        self::assertNull(StreamWindow::since('2026-11-22T00:00:00Z', $now));
        self::assertNull(StreamWindow::ofDays('the last week', $now));
    }
}
