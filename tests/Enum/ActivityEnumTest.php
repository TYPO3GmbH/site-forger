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
use App\Elastic\Bucket;
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

    public function testRangesCoverAllDatesWithoutGaps(): void
    {
        self::assertSame(['gte' => 'now-14d'], ActivityEnum::FRESH->range());
        self::assertSame(['gte' => 'now-60d', 'lt' => 'now-14d'], ActivityEnum::QUIET->range());
        self::assertSame(['lt' => 'now-180d'], ActivityEnum::ORPHANED->range());

        $cases = ActivityEnum::cases();
        for ($i = 1, $count = count($cases); $i < $count; ++$i) {
            $younger = $cases[$i - 1]->range();
            $older = $cases[$i]->range();
            self::assertArrayHasKey('gte', $younger);
            self::assertArrayHasKey('lt', $older);
            self::assertSame($younger['gte'], $older['lt']);
        }
    }

    public function testFiltersLeaveOutClosedChangesAndIssues(): void
    {
        self::assertSame(
            ['bool' => [
                'filter' => [['range' => ['updated_on' => ['gte' => 'now-180d', 'lt' => 'now-60d']]]],
                'must_not' => [['terms' => ['reviewstatus' => ['MERGED', 'ABANDONED']]]],
            ]],
            ActivityEnum::STALE->changeQuery()
        );
        self::assertSame(
            ['bool' => [
                'filter' => [['range' => ['updated_on' => ['lt' => 'now-180d']]]],
                'must_not' => [['terms' => ['status.id' => [5, 6, 3]]]],
            ]],
            ActivityEnum::ORPHANED->issueQuery()
        );
    }

    public function testAggregationsCountWithTheFilters(): void
    {
        $changes = ActivityEnum::changeAggregation()['filters']['filters'];
        $issues = ActivityEnum::issueAggregation()['filters']['filters'];

        self::assertSame(['Fresh', 'Quiet', 'Stale', 'Orphaned'], array_keys($changes));
        self::assertSame(ActivityEnum::QUIET->changeQuery(), $changes['Quiet']);
        self::assertSame(ActivityEnum::QUIET->issueQuery(), $issues['Quiet']);
    }

    public function testBucketsFollowTheStagesWithTheLabelsAsKeys(): void
    {
        // Elasticsearch answers a keyed "filters" aggregation, in any order
        $result = ['buckets' => ['Stale' => ['doc_count' => 3], 'Fresh' => ['doc_count' => 7], 'Orphaned' => ['doc_count' => 1]]];

        $buckets = ActivityEnum::buckets(Bucket::facetOf($result));

        self::assertSame(
            [['Fresh' => 7], ['Quiet' => 0], ['Stale' => 3], ['Orphaned' => 1]],
            $buckets->map(static fn (Bucket $bucket): array => [$bucket->key => $bucket->count])->toArray(),
        );
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
