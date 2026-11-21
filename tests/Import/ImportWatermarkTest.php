<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Import;

use App\Import\ImportWatermark;
use PHPUnit\Framework\TestCase;

final class ImportWatermarkTest extends TestCase
{
    public function testTheDeltaStartsBeforeTheLatestChangeInTheIndex(): void
    {
        $latest = new \DateTimeImmutable('2026-11-19 15:10:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-11-19 15:00:00', ImportWatermark::fromLatest($latest->getTimestamp())->format('Y-m-d H:i:s'));
    }

    public function testOnlyChangedOrNewDocumentsAreImported(): void
    {
        $updated = [
            '101' => '2026/11/19 15:00:00',
            '102' => '2026/11/19 15:05:00',
            '103' => '2026/11/19 15:06:00',
        ];
        $stored = [
            // unchanged, e.g. imported by the previous run within the overlap
            '101' => '2026/11/19 15:00:00',
            // changed again since
            '102' => '2026/11/19 14:00:00',
            // 103 is not in the index yet
        ];

        self::assertSame(['102', '103'], ImportWatermark::changed($updated, $stored));
        self::assertSame([], ImportWatermark::changed([], $stored));
        // Gerrit change numbers come as int keys
        self::assertSame(['7'], ImportWatermark::changed([7 => '2026/11/19 15:00:00'], []));
    }

    public function testCatchingUpTakesTheOldestWindowThatFits(): void
    {
        $since = new \DateTimeImmutable('2026-11-19 00:00:00 UTC');
        $tried = [];
        // The changes of the first two hours fit in a run
        $until = ImportWatermark::catchUpWindow($since, 86400, 60, static function (\DateTimeImmutable $until) use ($since, &$tried): bool {
            $tried[] = $until->getTimestamp() - $since->getTimestamp();

            return $until->getTimestamp() - $since->getTimestamp() <= 7200;
        });

        self::assertSame([86400, 43200, 21600, 10800, 5400], $tried);
        self::assertSame('2026-11-19 01:30:00', $until->format('Y-m-d H:i:s'));
    }

    public function testCatchingUpEndsAtTheShortestWindow(): void
    {
        $since = new \DateTimeImmutable('2026-11-19 00:00:00 UTC');
        $tries = 0;
        $until = ImportWatermark::catchUpWindow($since, 480, 60, static function () use (&$tries): bool {
            ++$tries;

            return false;
        });

        // 480, 240, 120, 60 seconds: the last is taken though it does not fit
        self::assertSame(4, $tries);
        self::assertSame('2026-11-19 00:01:00', $until->format('Y-m-d H:i:s'));
    }

    public function testTheFiltersAreInUtc(): void
    {
        // The index stores UTC, whatever the time zone of PHP is
        $since = new \DateTimeImmutable('2026-11-19 16:00:00', new \DateTimeZone('Europe/Berlin'));

        self::assertSame('>=2026-11-19T15:00:00Z', ImportWatermark::redmineFilter($since));
        self::assertSame('after:"2026-11-19 15:00:00 +0000"', ImportWatermark::gerritOperator($since));
        self::assertSame('before:"2026-11-19 15:00:00 +0000"', ImportWatermark::gerritBefore($since));
    }
}
