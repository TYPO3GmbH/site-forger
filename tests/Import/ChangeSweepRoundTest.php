<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Import;

use App\Enum\SystemCheckEnum;
use App\Import\ChangeSweepRound;
use PHPUnit\Framework\TestCase;

final class ChangeSweepRoundTest extends TestCase
{
    public function testTheFirstPageStartsARound(): void
    {
        $now = new \DateTimeImmutable('2026-11-24 19:00:00');
        $next = new \DateTimeImmutable('2026-11-12 21:01:21');

        $round = new ChangeSweepRound()->after($next, 3, 1, $now);

        self::assertEquals($next, $round->until);
        self::assertEquals($now, $round->started);
        self::assertSame(3, $round->added);
        self::assertSame(1, $round->removed);
        self::assertNull($round->lastFinished);
    }

    public function testAPageAddsToTheRound(): void
    {
        $started = new \DateTimeImmutable('2026-11-24 19:00:00');
        $round = new ChangeSweepRound(new \DateTimeImmutable('2026-11-12 21:01:21'), $started, 3, 1);

        $round = $round->after(new \DateTimeImmutable('2026-10-30 21:27:59'), 2, 0, new \DateTimeImmutable('now'));

        self::assertEquals($started, $round->started);
        self::assertSame(5, $round->added);
        self::assertSame(1, $round->removed);
    }

    public function testTheLastPageEndsTheRound(): void
    {
        $started = new \DateTimeImmutable('2026-11-24 19:00:00');
        $now = new \DateTimeImmutable('2026-11-25 05:00:00');
        $round = new ChangeSweepRound(new \DateTimeImmutable('2012-03-01 00:00:00'), $started, 3, 1);

        $round = $round->after(null, 2, 0, $now);

        self::assertNull($round->until);
        self::assertNull($round->started);
        self::assertSame(0, $round->added);
        self::assertEquals($started, $round->lastStarted);
        self::assertEquals($now, $round->lastFinished);
        self::assertSame(5, $round->lastAdded);
        self::assertSame(1, $round->lastRemoved);
    }

    public function testAttentionUntilARoundFinished(): void
    {
        $halfWay = new ChangeSweepRound(new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2026-11-24'));

        self::assertSame(SystemCheckEnum::ATTENTION, $halfWay->result());
    }

    public function testAttentionWhenTheLastRoundFoundDifferences(): void
    {
        $round = new ChangeSweepRound(lastFinished: new \DateTimeImmutable('2026-11-25'), lastAdded: 56000);

        self::assertSame(SystemCheckEnum::ATTENTION, $round->result());
    }

    public function testOkWhenTheLastRoundFoundTheIndexComplete(): void
    {
        $round = new ChangeSweepRound(lastFinished: new \DateTimeImmutable('2026-11-25'));

        self::assertSame(SystemCheckEnum::OK, $round->result());
    }
}
