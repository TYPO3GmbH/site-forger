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
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testThePagesAreThoseTheListsLinkedBefore(): void
    {
        foreach ([0, 1, 24, 25, 26, 250, 374, 375, 376, 1000, 5000] as $total) {
            foreach (range(1, 50) as $page) {
                self::assertSame(self::before($total, $page), new Pagination($page, $total)->pages(), sprintf('%d results, page %d', $total, $page));
            }
        }
    }

    public function testTheLastPageShowsWhereItEnds(): void
    {
        $viewData = new Pagination(2, 30)->viewData();

        self::assertSame([26, 30, 0, 1], [$viewData['startingAtItem'], $viewData['endingAtItem'], $viewData['next'], $viewData['prev']]);
    }

    public function testAPageBelowTheFirstIsTheFirst(): void
    {
        self::assertSame(0, new Pagination(-3, 100)->from());
    }

    /**
     * The pages as ReviewController::getPages() and SearchController::getPages() linked them.
     *
     * @return array<int, int>
     */
    private static function before(int $total, int $currentPage): array
    {
        $numPages = ceil($total / 25);
        $i = 0;
        $maxPages = $numPages;
        if ($numPages > 15 && $currentPage <= 7) {
            $numPages = 15;
        }
        if ($currentPage > 7) {
            $i = $currentPage - 7;
            $numPages = $currentPage + 6;
        }
        if ($numPages > $maxPages) {
            $numPages = $maxPages;
            $i = $maxPages - 15;
        }
        if ($i < 0) {
            $i = 0;
        }
        $out = [];
        while ($i < $numPages) {
            $out[(int) $i] = (int) ($i + 1);
            ++$i;
        }

        return $out;
    }
}
