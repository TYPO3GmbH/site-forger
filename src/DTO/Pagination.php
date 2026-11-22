<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\DTO;

/**
 * A page of a list of results, with the links to the pages around it.
 */
final readonly class Pagination
{
    public const int PER_PAGE = 25;

    /**
     * Elasticsearch returns no result beyond this one, see its setting "index.max_result_window". The total counts
     * further, the pages end here.
     */
    public const int MAX_RESULTS = 10000;

    /**
     * The links show this many pages before and after the current one, so the pagination fits a phone.
     */
    private const int AROUND = 2;

    public int $page;

    public function __construct(int $page, public int $total = 0, public int $perPage = self::PER_PAGE)
    {
        $this->page = max(1, min($page, self::reachablePages($perPage)));
    }

    public function withTotal(int $total): self
    {
        return new self($this->page, $total, $this->perPage);
    }

    /**
     * The offset of the first result, for "from" of Elasticsearch.
     */
    public function from(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function lastPage(): int
    {
        return min((int) ceil($this->total / $this->perPage), self::reachablePages($this->perPage));
    }

    private static function reachablePages(int $perPage): int
    {
        return intdiv(self::MAX_RESULTS, max(1, $perPage));
    }

    /**
     * @return array<int, int> the numbers of the pages to link, keyed from 0
     */
    public function pages(): array
    {
        $last = $this->lastPage();
        $first = max(1, min($this->page - self::AROUND, $last - 2 * self::AROUND));
        $end = min($last, $first + 2 * self::AROUND);
        $pages = [];
        for ($number = $first; $number <= $end; ++$number) {
            $pages[$number - 1] = $number;
        }

        return $pages;
    }

    /**
     * What the templates of the lists expect, see _partials/pagination.html.twig.
     *
     * @return array{pagesToLinkTo: array<int, int>, currentPage: int, lastPage: int, prev: int, next: int, totalResults: int, startingAtItem: int, endingAtItem: int}
     */
    public function viewData(): array
    {
        return [
            'pagesToLinkTo' => $this->pages(),
            'currentPage' => $this->page,
            'lastPage' => $this->lastPage(),
            'prev' => $this->page - 1,
            'next' => $this->page < $this->lastPage() ? $this->page + 1 : 0,
            'totalResults' => $this->total,
            'startingAtItem' => $this->from() + 1,
            'endingAtItem' => min($this->page * $this->perPage, $this->total),
        ];
    }
}
