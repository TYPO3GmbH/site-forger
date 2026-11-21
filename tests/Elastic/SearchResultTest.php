<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\SearchResult;
use Elastica\Query;
use Elastica\Response;
use Elastica\ResultSet;
use Elastica\ResultSet\DefaultBuilder;
use PHPUnit\Framework\TestCase;

final class SearchResultTest extends TestCase
{
    public function testReadsTheDocumentsInTheOrderOfTheResponse(): void
    {
        $result = SearchResult::of(
            self::resultSet([['_source' => ['id' => 2]], ['_source' => ['id' => 1]]]),
            static fn (array $source): int => (int) $source['id'],
        );

        self::assertSame([2, 1], $result->toArray());
        self::assertCount(2, $result);
    }

    public function testKeepsTheTotalAndTheMaxScore(): void
    {
        $result = SearchResult::of(
            self::resultSet([['_source' => []]], 42, 1.5),
            static fn (array $source): array => $source,
        );

        self::assertSame(42, $result->total);
        self::assertSame(1.5, $result->maxScore);
    }

    public function testCountsTheBucketsOfAnAggregation(): void
    {
        $aggregations = ['status' => ['buckets' => [['key' => 'New', 'doc_count' => 3]]]];
        $result = SearchResult::of(
            self::resultSet([], 0, 0.0, $aggregations),
            static fn (array $source): array => $source,
        );

        self::assertSame(['New' => 3], $result->counts('status'));
        self::assertSame(3, $result->buckets('status')->get('New')?->count);
        self::assertSame([], $result->counts('unknown'));
    }

    public function testFilterReturnsACollectionOfTheDocuments(): void
    {
        $result = SearchResult::ofDocuments([1, 2, 3]);

        self::assertSame([1 => 2], $result->filter(static fn (int $number): bool => 2 === $number)->toArray());
        self::assertSame(3, $result->total);
    }

    /**
     * @param list<array<string, mixed>> $hits
     * @param array<string, mixed>       $aggregations
     */
    private static function resultSet(
        array $hits,
        int $total = 0,
        float $maxScore = 0.0,
        array $aggregations = [],
    ): ResultSet {
        $data = ['hits' => ['hits' => $hits, 'total' => ['value' => $total], 'max_score' => $maxScore]];
        if ([] !== $aggregations) {
            $data['aggregations'] = $aggregations;
        }

        return (new DefaultBuilder())->buildResultSet(new Response($data), new Query());
    }
}
