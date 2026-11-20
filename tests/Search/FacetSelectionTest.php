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
use App\Search\FacetSelection;
use PHPUnit\Framework\TestCase;

final class FacetSelectionTest extends TestCase
{
    public function testReadsSelectedValuesWithSpaces(): void
    {
        $filters = ['owner' => ['Benni_Mack' => 'true', 'Lina_Wolf' => 'false'], 'type' => ['TASK' => 'true']];

        self::assertSame(['Benni Mack'], FacetSelection::selected($filters, 'owner'));
        self::assertSame([], FacetSelection::selected($filters, 'author'));
    }

    public function testAsksForTheSelectedOnesWithAnAggregationOfTheirOwn(): void
    {
        $aggregations = ['Owner' => FacetSelection::aggregation('owner')];

        self::assertSame($aggregations, FacetSelection::withSelected($aggregations, 'Owner', []));
        self::assertSame($aggregations, FacetSelection::withSelected($aggregations, 'Author', ['Benni Mack']));
        self::assertSame(
            ['terms' => ['field' => 'owner', 'include' => ['Benni Mack', 'Lina Wolf'], 'size' => 2]],
            FacetSelection::withSelected($aggregations, 'Owner', ['Benni Mack', 'Lina Wolf'])['Owner (selected)']
        );
    }

    public function testShowsAsManyValuesAsAsked(): void
    {
        // Each of the shards tells all values: the counts are exact
        self::assertSame(['terms' => ['field' => 'owner', 'size' => FacetSelection::SIZE, 'shard_size' => FacetSelection::SHARD_SIZE]], FacetSelection::aggregation('owner'));
        self::assertSame(['terms' => ['field' => 'tags', 'size' => 30, 'shard_size' => FacetSelection::SHARD_SIZE]], FacetSelection::aggregation('tags', 30));
    }

    public function testAddsSelectedOnesTheFilterDoesNotShow(): void
    {
        $facets = [
            'Owner' => Bucket::facetOf(['buckets' => [['key' => 'Benni Mack', 'doc_count' => 16]]]),
            'Owner (selected)' => Bucket::facetOf(['buckets' => [
                ['key' => 'Benni Mack', 'doc_count' => 16],
                ['key' => 'Lina Wolf', 'doc_count' => 1],
            ]]),
            'Topic' => Bucket::facetOf(null),
        ];

        $merged = FacetSelection::merge($facets, 'Owner');

        self::assertSame(['Owner', 'Topic'], array_keys($merged));
        self::assertSame(
            [['Benni Mack' => 16], ['Lina Wolf' => 1]],
            $merged['Owner']->map(static fn (Bucket $bucket): array => [$bucket->key => $bucket->count])->toArray(),
        );
        self::assertSame(['Topic'], array_keys(FacetSelection::merge(['Topic' => Bucket::facetOf(null)], 'Owner')));
    }
}
