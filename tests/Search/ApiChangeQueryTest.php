<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Api\ChangeFilters;
use App\Api\ListParameters;
use App\Api\PersonFilter;
use App\Enum\ChangeListEnum;
use App\Enum\ChangeStateFilterEnum;
use App\Enum\ReviewSortEnum;
use App\Search\ApiChangeQuery;
use App\Search\PersonQuery;
use App\Search\ReviewLists;
use App\Search\ReviewSearch;
use App\Search\ReviewSizeQuery;
use PHPUnit\Framework\TestCase;

final class ApiChangeQueryTest extends TestCase
{
    public function testListsTheOpenChangesWithoutSearchWords(): void
    {
        $bool = self::bool(new ChangeFilters());

        self::assertEquals([['match_all' => new \stdClass()]], $bool['must']);
        self::assertSame([['term' => ['reviewstatus' => 'NEW']]], $bool['filter']);
    }

    public function testSearchesTheWordsInEveryStatus(): void
    {
        $bool = self::bool(new ChangeFilters(ChangeStateFilterEnum::ALL), new ListParameters('97865'));

        self::assertSame([ReviewSearch::query('97865')], $bool['must']);
        self::assertArrayNotHasKey('filter', $bool);
    }

    public function testTakesMasterForMain(): void
    {
        $bool = self::bool(new ChangeFilters(ChangeStateFilterEnum::MERGED, branch: 'master'));

        self::assertSame(
            [['term' => ['reviewstatus' => 'MERGED']], ['term' => ['branch' => 'main']]],
            $bool['filter'],
        );
    }

    public function testNarrowsAReviewListByTheSearchWords(): void
    {
        $bool = self::bool(new ChangeFilters(list: ChangeListEnum::READY), new ListParameters('cache'));

        self::assertSame([ReviewSearch::query('cache'), ReviewLists::query(ReviewLists::READY)], $bool['must']);
    }

    public function testFiltersByPathOwnerAndSize(): void
    {
        $filters = new ChangeFilters(
            ChangeStateFilterEnum::ALL,
            path: 'typo3/sysext/core',
            owner: PersonFilter::number(14410),
            maxLines: 50,
        );

        self::assertSame(
            [
                ReviewLists::fileQuery('typo3/sysext/core'),
                ['term' => ['owner_id' => 14410]],
                ReviewSizeQuery::atMost(50),
            ],
            self::bool($filters)['filter'],
        );
    }

    public function testFindsTheOwnerByName(): void
    {
        $filters = new ChangeFilters(ChangeStateFilterEnum::ALL, owner: PersonFilter::name(['wolters']));

        self::assertSame(
            [PersonQuery::query(PersonFilter::name(['wolters']), 'owner_id', 'owner')],
            self::bool($filters)['filter'],
        );
    }

    public function testEveryListIsAListOfTheReviewPages(): void
    {
        foreach (ChangeListEnum::cases() as $list) {
            self::assertNotNull(ReviewLists::query($list->reviewList()), $list->name);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function bool(ChangeFilters $filters, ListParameters $list = new ListParameters()): array
    {
        return ApiChangeQuery::request($list, $filters, ReviewSortEnum::UPDATED)['query']['bool'];
    }
}
