<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Elastic\Bucket;
use App\Enum\ActivityEnum;
use App\Search\ActivityQuery;
use PHPUnit\Framework\TestCase;

final class ActivityQueryTest extends TestCase
{
    public function testRangesCoverAllDatesWithoutGaps(): void
    {
        self::assertSame(['gte' => 'now-14d'], ActivityQuery::range(ActivityEnum::FRESH));
        self::assertSame(['gte' => 'now-60d', 'lt' => 'now-14d'], ActivityQuery::range(ActivityEnum::QUIET));
        self::assertSame(['lt' => 'now-180d'], ActivityQuery::range(ActivityEnum::ORPHANED));

        $cases = ActivityEnum::cases();
        for ($i = 1, $count = count($cases); $i < $count; ++$i) {
            $younger = ActivityQuery::range($cases[$i - 1]);
            $older = ActivityQuery::range($cases[$i]);
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
            ActivityQuery::changes(ActivityEnum::STALE)
        );
        self::assertSame(
            ['bool' => [
                'filter' => [['range' => ['updated_on' => ['lt' => 'now-180d']]]],
                'must_not' => [['terms' => ['status.id' => [5, 6, 3]]]],
            ]],
            ActivityQuery::issues(ActivityEnum::ORPHANED)
        );
    }

    public function testAggregationsCountWithTheFilters(): void
    {
        $changes = ActivityQuery::changeAggregation()['filters']['filters'];
        $issues = ActivityQuery::issueAggregation()['filters']['filters'];

        self::assertSame(['Fresh', 'Quiet', 'Stale', 'Orphaned'], array_keys($changes));
        self::assertSame(ActivityQuery::changes(ActivityEnum::QUIET), $changes['Quiet']);
        self::assertSame(ActivityQuery::issues(ActivityEnum::QUIET), $issues['Quiet']);
    }

    public function testBucketsFollowTheStagesWithTheLabelsAsKeys(): void
    {
        // Elasticsearch answers a keyed "filters" aggregation, in any order
        $result = ['buckets' => [
            'Stale' => ['doc_count' => 3],
            'Fresh' => ['doc_count' => 7],
            'Orphaned' => ['doc_count' => 1],
        ]];

        $buckets = ActivityQuery::facet(Bucket::facetOf($result));

        self::assertSame(
            [['Fresh' => 7], ['Quiet' => 0], ['Stale' => 3], ['Orphaned' => 1]],
            $buckets->map(static fn (Bucket $bucket): array => [$bucket->key => $bucket->count])->toArray(),
        );
    }
}
