<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\DTO;

use App\DTO\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, list<int>}>
     */
    public static function pages(): iterable
    {
        yield 'no results' => [0, 1, []];
        yield 'one page' => [25, 1, [1]];
        yield 'fewer pages than the window' => [75, 2, [1, 2, 3]];
        yield 'the first page' => [1000, 1, [1, 2, 3, 4, 5]];
        yield 'the second page' => [1000, 2, [1, 2, 3, 4, 5]];
        yield 'in the middle' => [1000, 20, [18, 19, 20, 21, 22]];
        yield 'the last but one' => [1000, 39, [36, 37, 38, 39, 40]];
        yield 'the last page' => [1000, 40, [36, 37, 38, 39, 40]];
        yield 'beyond the last page' => [75, 9, [1, 2, 3]];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('pages')]
    public function testFivePagesAroundTheCurrentOneAreLinked(int $total, int $page, array $expected): void
    {
        self::assertSame($expected, array_values(new Pagination($page, $total)->pages()));
    }

    public function testTheLastPageShowsWhereItEnds(): void
    {
        $viewData = new Pagination(2, 30)->viewData();

        self::assertSame([26, 30, 0, 1], [$viewData['startingAtItem'], $viewData['endingAtItem'], $viewData['next'], $viewData['prev']]);
    }

    public function testThePagesEndWhereElasticsearchStopsReturningResults(): void
    {
        $page = new Pagination(9999, 61211);

        self::assertSame(400, $page->page);
        self::assertSame(400, $page->lastPage());
        self::assertSame(61211, $page->viewData()['totalResults']);
        self::assertSame(Pagination::MAX_RESULTS - Pagination::PER_PAGE, $page->from());
    }

    public function testAPageBelowTheFirstIsTheFirst(): void
    {
        self::assertSame(0, new Pagination(-3, 100)->from());
    }
}
