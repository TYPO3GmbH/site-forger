<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\FacetSelection;
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
        $aggregations = [
            'Owner' => ['buckets' => [['key' => 'Benni Mack', 'doc_count' => 16]]],
            'Owner (selected)' => ['buckets' => [['key' => 'Benni Mack', 'doc_count' => 16], ['key' => 'Lina Wolf', 'doc_count' => 1]]],
            'Topic' => ['buckets' => []],
        ];

        self::assertSame(
            [
                'Owner' => ['buckets' => [['key' => 'Benni Mack', 'doc_count' => 16], ['key' => 'Lina Wolf', 'doc_count' => 1]]],
                'Topic' => ['buckets' => []],
            ],
            FacetSelection::merge($aggregations, 'Owner')
        );
        self::assertSame(['Topic' => ['buckets' => []]], FacetSelection::merge(['Topic' => ['buckets' => []]], 'Owner'));
    }
}
