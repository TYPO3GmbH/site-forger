<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\People\Users;
use Elastica\Index;

/**
 * Finds the people for the search in a filter of people, see PersonSuggestion.
 */
final readonly class PersonSuggestions
{
    public function __construct(
        private Users $users,
    ) {
    }

    /**
     * @param array<string, mixed> $listQuery the query of the list the filter belongs to
     * @param string               $title     the filter, a key of PeopleFacet::FILTERS
     *
     * @return list<array{key: string, name: string, count: int, avatar: string|null}>
     */
    public function find(Index $index, string $title, string $term, array $listQuery): array
    {
        $request = PersonSuggestion::request($term, $listQuery, $title);
        if (null === $request) {
            return [];
        }

        return PersonSuggestion::fromAggregations(
            $index->search($request)->getAggregations(),
            $title,
            fn (array $user): ?string => $this->users->avatarOfUser($user, PersonSuggestion::AVATAR_SIZE),
            $this->users->keyOf(...),
        );
    }
}
