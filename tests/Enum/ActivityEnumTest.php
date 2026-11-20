<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Enum;

use App\Document\Change;
use App\Document\Issue;
use App\Enum\ActivityEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActivityEnumTest extends TestCase
{
    private const string NOW = '2026-11-19 12:00:00';

    /**
     * @return iterable<string, array{string, ActivityEnum}>
     */
    public static function lastActivities(): iterable
    {
        yield 'an hour ago' => ['2026/11/19 11:00:00', ActivityEnum::FRESH];
        yield 'exactly 14 days ago' => ['2026/11/05 12:00:00', ActivityEnum::FRESH];
        yield 'just over 14 days ago' => ['2026/11/05 11:59:59', ActivityEnum::QUIET];
        yield 'a month ago' => ['2026/10/19 12:00:00', ActivityEnum::QUIET];
        yield 'just over 60 days ago' => ['2026/09/20 11:59:59', ActivityEnum::STALE];
        yield 'exactly 180 days ago' => ['2026/05/23 12:00:00', ActivityEnum::STALE];
        yield 'two years ago' => ['2024/11/19 12:00:00', ActivityEnum::ORPHANED];
    }

    #[DataProvider('lastActivities')]
    public function testStageOfTheLastActivity(string $lastActivity, ActivityEnum $expected): void
    {
        $change = ['reviewstatus' => 'NEW', 'updated_on' => $lastActivity, 'created_on' => '2020/01/01 00:00:00'];

        self::assertSame($expected, Change::fromDocument($change)->activity(new \DateTimeImmutable(self::NOW)));
    }

    public function testClosedChangesHaveNoStage(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        self::assertNull(Change::fromDocument(['reviewstatus' => 'MERGED', 'updated_on' => '2020/01/01 00:00:00'])->activity($now));
        self::assertNull(Change::fromDocument(['reviewstatus' => 'ABANDONED', 'updated_on' => '2020/01/01 00:00:00'])->activity($now));
        self::assertSame(ActivityEnum::ORPHANED, Change::fromDocument(['reviewstatus' => 'NEW', 'work_in_progress' => true, 'updated_on' => '2020/01/01 00:00:00'])->activity($now));
    }

    public function testDocumentsWithoutAnUpdateFallBackToTheCreation(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        self::assertSame(ActivityEnum::STALE, Change::fromDocument(['reviewstatus' => 'NEW', 'created_on' => '2026/07/01 00:00:00'])->activity($now));
        self::assertNull(Change::fromDocument(['reviewstatus' => 'NEW'])->activity($now));
    }

    public function testIssuesHaveAStageUnlessClosed(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $old = ['updated_on' => '2026/07/01 00:00:00', 'created_on' => '2020/01/01 00:00:00'];

        self::assertSame(ActivityEnum::STALE, Issue::fromDocument($old + ['status' => ['id' => 8, 'name' => 'Under Review']])->activity($now));
        self::assertNull(Issue::fromDocument($old + ['status' => ['id' => 3, 'name' => 'Resolved']])->activity($now));
        // Statuses Forge does not know count as open
        self::assertSame(ActivityEnum::STALE, Issue::fromDocument($old + ['status' => ['id' => 99]])->activity($now));
        self::assertSame(ActivityEnum::STALE, Issue::fromDocument($old)->activity($now));
    }

    public function testLabelsAreTheFilterKeys(): void
    {
        foreach (ActivityEnum::cases() as $stage) {
            self::assertSame($stage, ActivityEnum::tryFromLabel($stage->label()));
        }
        self::assertNull(ActivityEnum::tryFromLabel('fresh'));
    }

    public function testOnlyStagesOfDocumentsLyingStillAreNoteworthy(): void
    {
        self::assertFalse(ActivityEnum::FRESH->isNoteworthy());
        self::assertTrue(ActivityEnum::QUIET->isNoteworthy());
        self::assertTrue(ActivityEnum::STALE->isNoteworthy());
        self::assertTrue(ActivityEnum::ORPHANED->isNoteworthy());
    }
}
