<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Api\ListParameters;
use App\Enum\ChangeStateFilterEnum;
use App\Enum\ReviewSortEnum;
use App\Search\ApiChangeQuery;
use App\Search\ReviewSearch;
use PHPUnit\Framework\TestCase;

final class ApiChangeQueryTest extends TestCase
{
    public function testListsTheOpenChangesWithoutSearchWords(): void
    {
        $bool = self::bool(new ListParameters(), ChangeStateFilterEnum::OPEN, null);

        self::assertEquals([['match_all' => new \stdClass()]], $bool['must']);
        self::assertSame([['term' => ['reviewstatus' => 'NEW']]], $bool['filter']);
    }

    public function testSearchesTheWordsInEveryStatus(): void
    {
        $bool = self::bool(new ListParameters('97865'), ChangeStateFilterEnum::ALL, null);

        self::assertSame([ReviewSearch::query('97865')], $bool['must']);
        self::assertArrayNotHasKey('filter', $bool);
    }

    public function testTakesMasterForMain(): void
    {
        $bool = self::bool(new ListParameters(), ChangeStateFilterEnum::MERGED, 'master');

        self::assertSame(
            [['term' => ['reviewstatus' => 'MERGED']], ['term' => ['branch' => 'main']]],
            $bool['filter'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function bool(ListParameters $list, ChangeStateFilterEnum $state, ?string $branch): array
    {
        return ApiChangeQuery::request($list, $state, $branch, ReviewSortEnum::UPDATED)['query']['bool'];
    }
}
