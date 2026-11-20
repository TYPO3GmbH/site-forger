<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\People;

use App\Enum\SystemCheckEnum;
use App\People\UserHealth;
use PHPUnit\Framework\TestCase;

final class UserHealthTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-20 18:00:00', new \DateTimeZone('UTC'));
    }

    public function testUsersWaitingForForgeAreProgressNotAProblem(): void
    {
        $health = UserHealth::of(['total' => 1033, 'withAvatar' => 621, 'waiting' => 1003, 'gone' => 0, 'lastFetched' => $this->now->modify('-4 minutes')], $this->now);

        self::assertSame(SystemCheckEnum::OK, $health['result']);
        self::assertSame('Avatars for 621 of 1,033 people. 1,003 wait for Forge, all asked for in about 3 h.', $health['summary']);
        self::assertNull($health['hint']);
    }

    public function testAllAskedFor(): void
    {
        $health = UserHealth::of(['total' => 1033, 'withAvatar' => 1020, 'waiting' => 0, 'gone' => 5, 'lastFetched' => $this->now->modify('-3 days')], $this->now);

        self::assertSame(SystemCheckEnum::OK, $health['result']);
        self::assertSame('Avatars for 1,020 of 1,033 people. Forge was asked for all of them.', $health['summary']);
    }

    public function testUsersWaitingWhileForgeIsNotAskedIsAProblem(): void
    {
        $stale = UserHealth::of(['total' => 1033, 'withAvatar' => 621, 'waiting' => 1003, 'gone' => 0, 'lastFetched' => $this->now->modify('-2 hours')], $this->now);
        $never = UserHealth::of(['total' => 997, 'withAvatar' => 591, 'waiting' => 997, 'gone' => 0, 'lastFetched' => null], $this->now);

        self::assertSame(SystemCheckEnum::PROBLEM, $stale['result']);
        self::assertStringContainsString('which was not asked for since 20 Nov 2026, 16:00 UTC', $stale['summary']);
        self::assertSame(SystemCheckEnum::PROBLEM, $never['result']);
        self::assertStringContainsString('not asked for ever', $never['summary']);
        self::assertNotNull($never['hint']);
    }

    public function testManyUsersUnknownToForgeNeedAttention(): void
    {
        // A token that may not see users any more gets 403 for all of them
        $health = UserHealth::of(['total' => 1033, 'withAvatar' => 600, 'waiting' => 900, 'gone' => 120, 'lastFetched' => $this->now], $this->now);
        $few = UserHealth::of(['total' => 1033, 'withAvatar' => 600, 'waiting' => 900, 'gone' => 10, 'lastFetched' => $this->now], $this->now);

        self::assertSame(SystemCheckEnum::ATTENTION, $health['result']);
        self::assertStringContainsString('Forge does not know 120 of the 133 users asked for.', $health['summary']);
        self::assertSame(SystemCheckEnum::OK, $few['result']);
    }

    public function testNoUsersYetNeedsAttention(): void
    {
        self::assertSame(SystemCheckEnum::ATTENTION, UserHealth::of(['total' => 0, 'withAvatar' => 0, 'waiting' => 0, 'gone' => 0, 'lastFetched' => null], $this->now)['result']);
    }
}
