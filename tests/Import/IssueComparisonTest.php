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
use App\Import\IssueComparison;
use PHPUnit\Framework\TestCase;

final class IssueComparisonTest extends TestCase
{
    public function testTheFirstAlikeComparisonStartsTheTimeAlike(): void
    {
        $at = new \DateTimeImmutable('2026-11-24 19:28');

        $comparison = IssueComparison::of(null, $at, 61241, 61241);

        self::assertEquals($at, $comparison->alikeSince);
        self::assertSame(SystemCheckEnum::OK, $comparison->result());
        self::assertSame(1.0, $comparison->share());
    }

    public function testAlikeComparisonsKeepWhenTheyStarted(): void
    {
        $since = new \DateTimeImmutable('2026-11-24 19:28');
        $before = IssueComparison::of(null, $since, 61241, 61241);

        $comparison = IssueComparison::of($before, new \DateTimeImmutable('2026-11-24 19:33'), 61241, 61241);

        self::assertEquals($since, $comparison->alikeSince);
    }

    public function testADifferenceEndsTheTimeAlike(): void
    {
        $before = IssueComparison::of(null, new \DateTimeImmutable('2026-11-24 19:28'), 61241, 61241);

        $comparison = IssueComparison::of($before, new \DateTimeImmutable('2026-11-24 19:33'), 61242, 61241, 1, 1);

        self::assertNull($comparison->alikeSince);
        self::assertSame(1, $comparison->difference());
        self::assertSame(1, $comparison->ranges);
        self::assertSame(1, $comparison->added);
        self::assertSame(SystemCheckEnum::ATTENTION, $comparison->result());
    }

    public function testTheShareTellsHowFullTheIndexIs(): void
    {
        $at = new \DateTimeImmutable('2026-11-24 19:28');

        self::assertSame(0.5, IssueComparison::of(null, $at, 60000, 30000)->share());
        // More issues in the index than on Forge: deleted ones the next runs remove
        self::assertSame(0.5, IssueComparison::of(null, $at, 30000, 60000)->share());
        self::assertSame(1.0, IssueComparison::of(null, $at, 0, 0)->share());
    }
}
