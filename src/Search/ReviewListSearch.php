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
use App\Elastic\Indices;
use App\Enum\ActivityEnum;
use App\Enum\ReviewSizeEnum;
use App\Enum\ReviewSortEnum;
use App\People\UserDirectory;
use App\Repository\ChangeRepository;

/**
 * The review lists and the search of the changes with their filters, see ReviewLists and ReviewController.
 */
final readonly class ReviewListSearch
{
    public function __construct(
        private Indices $indices,
        private UserDirectory $users,
        private PersonSuggestions $suggestions,
    ) {
    }

    /**
     * @param array<string, mixed>|null $listQuery    the query of the list, see ReviewLists::query()
     * @param array<string, mixed>      $aggregations see ReviewLists::aggregations()
     * @param array<mixed>              $filters      the filters of the request
     *
     * @return array<string, mixed> the data for the result list
     */
    public function search(
        ?array $listQuery,
        ReviewSortEnum $sort,
        array $aggregations,
        array $filters,
        Pagination $page,
    ): array {
        $request = [
            'sort' => $sort->sort(),
            'size' => $page->perPage,
            'from' => $page->from(),
        ];
        if (null !== $listQuery) {
            $request['query'] = $listQuery;
        }
        $filterQuery = ReviewLists::filterQuery($filters, $this->users);
        // A BoolQuery without clauses cannot be turned into an array
        if ($filterQuery->count() > 0 && [] !== $filterQuery->toArray()) {
            $request['post_filter'] = $filterQuery;
        }
        // Selected owners show in the filter, also when they are not among those with the most changes
        $owners = PeopleFacet::selection('Owner', FacetSelection::keys($filters, 'owner'), $this->users);
        $request['aggregations'] = FacetSelection::withSelected($aggregations, 'Owner', array_keys($owners));
        $changes = ChangeRepository::fromResultSet($this->indices->reviews()->createSearch($request)->search());

        $merged = FacetSelection::merge($changes->facets(), 'Owner');
        $found = FacetSelection::inOrderOf($merged, array_keys($request['aggregations']));
        // The checkboxes of the owners send their keys
        if (isset($found['Owner'])) {
            $found['Owner'] = PeopleFacet::keyed($found['Owner'], 'Owner', $this->users, $owners);
        }
        if (isset($found['Activity'])) {
            $found['Activity'] = ActivityEnum::buckets($found['Activity']);
        }
        if (isset($found['Size'])) {
            $found['Size'] = ReviewSizeEnum::buckets($found['Size']);
        }

        return $page->withTotal($changes->total)->viewData() + [
            'changes' => $changes,
            'aggregations' => $found,
        ];
    }

    /**
     * The owners of the changes in the list whose name contains the term.
     *
     * @param array<string, mixed> $listQuery the query of the list, see ReviewLists::query()
     *
     * @return list<array{key: string, name: string, count: int, avatar: string|null}>
     */
    public function owners(string $term, array $listQuery): array
    {
        return $this->suggestions->find($this->indices->reviews(), 'Owner', $term, $listQuery);
    }
}
