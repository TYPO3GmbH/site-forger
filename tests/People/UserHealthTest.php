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
use App\People\UserStatistics;
use PHPUnit\Framework\TestCase;

final class UserHealthTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-20 18:00:00', new \DateTimeZone('UTC'));
    }

    public function testThePeopleWithoutAnEmailAreCountedByWhy(): void
    {
        $health = UserHealth::of(new UserStatistics(
            total: 5632,
            mapped: 3432,
            notAskedYet: 1375,
            unknownToForge: 54,
            withoutEmail: 771,
            gone: 54,
            goneByStatus: [403 => 50, 404 => 4],
            lastFetched: $this->now->modify('-4 minutes'),
        ), $this->now);

        self::assertSame('2,200 of 5,632 people cannot be mapped to an email.', $health['summary']);
        self::assertSame(
            '1,375 not asked for yet · 54 unknown to Forge (50 refused (403), 4 not found (404))'
            . ' · 771 without an email on Forge',
            $health['reasons'],
        );
    }

    public function testPeopleNotAskedForYetAreProgressNotAProblem(): void
    {
        $recently = $this->now->modify('-4 minutes');
        $health = UserHealth::of(self::statistics(notAskedYet: 1003, lastFetched: $recently), $this->now);

        self::assertSame(SystemCheckEnum::OK, $health['result']);
        self::assertSame('"Update users" asks for the rest in about 3 h.', $health['hint']);
    }

    public function testEveryoneMapped(): void
    {
        $health = UserHealth::of(self::statistics(mapped: 1033), $this->now);

        self::assertSame(SystemCheckEnum::OK, $health['result']);
        self::assertSame('All 1,033 people are mapped to an email.', $health['summary']);
        self::assertNull($health['reasons']);
        self::assertNull($health['hint']);
    }

    public function testPeopleWaitingWhileForgeIsNotAskedIsAProblem(): void
    {
        $longAgo = $this->now->modify('-2 hours');
        $stale = UserHealth::of(self::statistics(notAskedYet: 1003, lastFetched: $longAgo), $this->now);
        $never = UserHealth::of(self::statistics(notAskedYet: 997), $this->now);

        self::assertSame(SystemCheckEnum::PROBLEM, $stale['result']);
        self::assertStringContainsString('not asked for users since 20 Nov 2026, 16:00 UTC', (string) $stale['hint']);
        self::assertSame(SystemCheckEnum::PROBLEM, $never['result']);
        self::assertStringContainsString('not asked for users ever', (string) $never['hint']);
    }

    public function testManyUsersUnknownToForgeNeedAttention(): void
    {
        // A token that may not see users any more gets 403 for all of them
        $many = UserHealth::of(self::statistics(notAskedYet: 900, gone: 120, lastFetched: $this->now), $this->now);
        $few = UserHealth::of(self::statistics(notAskedYet: 900, gone: 10, lastFetched: $this->now), $this->now);

        self::assertSame(SystemCheckEnum::ATTENTION, $many['result']);
        self::assertStringContainsString('Forge does not know 120 of the 133 users asked for.', (string) $many['hint']);
        self::assertSame(SystemCheckEnum::OK, $few['result']);
    }

    public function testTheAnswersOfForgeMostFirst(): void
    {
        self::assertSame(
            '100 refused (403), 18 not stored, 2 not found (404)',
            UserHealth::answers([403 => 100, 0 => 18, 404 => 2]),
        );
    }

    public function testNoUsersYetNeedsAttention(): void
    {
        $health = UserHealth::of(self::statistics(total: 0), $this->now);

        self::assertSame(SystemCheckEnum::ATTENTION, $health['result']);
    }

    /**
     * 1,033 people, those not mapped without a reason given.
     *
     * @param array<int, int> $goneByStatus
     */
    private static function statistics(
        int $total = 1033,
        int $mapped = 600,
        int $notAskedYet = 0,
        int $gone = 0,
        array $goneByStatus = [],
        ?\DateTimeImmutable $lastFetched = null,
    ): UserStatistics {
        return new UserStatistics($total, $mapped, $notAskedYet, $gone, 0, $gone, $goneByStatus, $lastFetched);
    }
}
