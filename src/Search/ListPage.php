<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\DTO\Pagination;
use App\Elastic\Bucket;
use App\Elastic\SearchResult;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * One page of a list with its filters: the issues of IssueListSearch or the changes of ReviewListSearch.
 *
 * @template-covariant T
 */
final readonly class ListPage
{
    /**
     * @param SearchResult<T>                                $results
     * @param array<string, ReadableCollection<int, Bucket>> $facets  the filters by their title
     * @param Pagination                                     $page    with the total of the results
     */
    public function __construct(
        public SearchResult $results,
        public array $facets,
        public Pagination $page,
    ) {
    }
}
