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
use App\Enum\ReviewSizeEnum;
use App\Search\ReviewSizeQuery;
use PHPUnit\Framework\TestCase;

final class ReviewSizeQueryTest extends TestCase
{
    public function testTheFilterOfASizeAsksForItsLines(): void
    {
        $filter = ReviewSizeQuery::changes(ReviewSizeEnum::NORMAL)['bool']['filter'];

        // Changes imported without lines have no size
        self::assertSame(['exists' => ['field' => 'insertions']], $filter[0]);
        self::assertSame(['minimum' => 51, 'maximum' => 250], $filter[1]['script']['script']['params']);
        $impossible = ReviewSizeQuery::changes(ReviewSizeEnum::IMPOSSIBLE)['bool']['filter'];
        self::assertSame(['minimum' => 10001, 'maximum' => -1], $impossible[1]['script']['script']['params']);
    }

    public function testTheFilterListsTheSizesSmallestFirst(): void
    {
        self::assertSame(
            ['Easy', 'Normal', 'Hard', 'Expert', 'Nightmare', 'Impossible'],
            array_keys(ReviewSizeQuery::changeAggregation()['filters']['filters']),
        );
        self::assertSame(
            [['Easy' => 4], ['Normal' => 0], ['Hard' => 1], ['Expert' => 0], ['Nightmare' => 0], ['Impossible' => 0]],
            ReviewSizeQuery::facet(Bucket::facetOf(['buckets' => [
                'Hard' => ['doc_count' => 1],
                'Easy' => ['doc_count' => 4],
            ]]))
                ->map(static fn (Bucket $bucket): array => [$bucket->key => $bucket->count])
                ->toArray(),
        );
    }

    public function testFindsTheChangesUpToSomeLines(): void
    {
        $script = ReviewSizeQuery::atMost(50)['script']['script'];

        self::assertSame(['maximum' => 50], $script['params']);
        self::assertStringStartsWith(ReviewSizeQuery::LINES_SCRIPT, $script['source']);
    }
}
