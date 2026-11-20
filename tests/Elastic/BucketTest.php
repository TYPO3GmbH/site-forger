<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\Bucket;
use PHPUnit\Framework\TestCase;

final class BucketTest extends TestCase
{
    public function testTermsBucketsAreKeyedByTheirKey(): void
    {
        $aggregation = ['buckets' => [['key' => 'Bug', 'doc_count' => 3], ['key' => 'Task', 'doc_count' => 1]]];

        self::assertSame(['Bug' => 3, 'Task' => 1], Bucket::countsOf($aggregation));
    }

    public function testFiltersBucketsAreKeyedByTheirName(): void
    {
        $aggregation = ['buckets' => ['ready' => ['doc_count' => 2], 'wip' => ['doc_count' => 0]]];

        self::assertSame(['ready' => 2, 'wip' => 0], Bucket::countsOf($aggregation));
    }

    public function testAMissingAggregationHasNoBuckets(): void
    {
        self::assertSame([], Bucket::countsOf(null));
        self::assertSame([], Bucket::countsOf([]));
    }

    public function testSubAggregationsAndFormattedKeys(): void
    {
        $year = Bucket::fromArray([
            'key' => 1735689600000,
            'key_as_string' => '2025/01/01 00:00:00',
            'doc_count' => 5,
            'month' => ['buckets' => [['key' => 1, 'key_as_string' => '2025/03/01 00:00:00', 'doc_count' => 5]]],
        ]);

        self::assertSame('2025/01/01 00:00:00', $year->keyAsString);
        self::assertSame(5, $year->count);
        self::assertSame(['1' => 5], $year->buckets('month')->map(static fn (Bucket $month): int => $month->count)->toArray());
        self::assertTrue($year->buckets('status')->isEmpty());
    }
}
