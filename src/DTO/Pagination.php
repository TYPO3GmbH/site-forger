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
     * The first pages link up to this many. Further on, the links show the pages around the current one.
     */
    private const int LINKED_PAGES = 15;
    private const int AROUND = 6;

    public int $page;

    public function __construct(int $page, public int $total = 0, public int $perPage = self::PER_PAGE)
    {
        $this->page = max(1, $page);
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
        return (int) ceil($this->total / $this->perPage);
    }

    /**
     * @return array<int, int> the numbers of the pages to link, keyed from 0
     */
    public function pages(): array
    {
        $last = $this->lastPage();
        if ($this->page <= self::AROUND + 1) {
            $first = 1;
            $end = min($last, self::LINKED_PAGES);
        } else {
            $first = $this->page - self::AROUND;
            $end = $this->page + self::AROUND;
            if ($end > $last) {
                $end = $last;
                $first = max(1, $last - self::LINKED_PAGES + 1);
            }
        }
        $pages = [];
        for ($number = $first; $number <= $end; ++$number) {
            $pages[$number - 1] = $number;
        }

        return $pages;
    }

    /**
     * What the templates of the lists expect, see _partials/pagination.html.twig.
     *
     * @return array{pagesToLinkTo: array<int, int>, currentPage: int, prev: int, next: int, totalResults: int, startingAtItem: int, endingAtItem: int}
     */
    public function viewData(): array
    {
        return [
            'pagesToLinkTo' => $this->pages(),
            'currentPage' => $this->page,
            'prev' => $this->page - 1,
            'next' => $this->page < $this->lastPage() ? $this->page + 1 : 0,
            'totalResults' => $this->total,
            'startingAtItem' => $this->from() + 1,
            'endingAtItem' => min($this->page * $this->perPage, $this->total),
        ];
    }
}
