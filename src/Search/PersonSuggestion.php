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

/**
 * People in a list whose name contains what was typed, for the search in a filter of people, see PeopleFacet:
 * the owners of Gerrit changes, the authors and assignees of issues.
 *
 * There are thousands of them, so the page does not hold them all but asks for the ones that match.
 * It asks with the query of the list, so it suggests the people and numbers the filter has.
 */
final class PersonSuggestion
{
    /**
     * Fewer characters match too many people to be of help.
     */
    public const int MIN_LENGTH = 2;

    public const int SIZE = 15;

    /**
     * Twice the size the avatar is shown in, for sharp screens.
     */
    public const int AVATAR_SIZE = 48;

    /**
     * Owner of changes the import does not know the author of.
     */
    private const string UNKNOWN = 'Unknown';

    /**
     * The search request for the people of a filter in the list whose name contains the term, any case, those with
     * the most first, each with its latest document, for the avatar.
     *
     * @param array<string, mixed> $listQuery the query of the list, like ReviewLists::query()
     * @param string               $title     the filter, a key of PeopleFacet::FILTERS
     *
     * @return array<string, mixed>|null null if the term is too short
     */
    public static function request(string $term, array $listQuery, string $title): ?array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return null;
        }
        $nameField = PeopleFacet::FILTERS[$title]['name'];

        return [
            'size' => 0,
            'query' => [
                'bool' => [
                    'filter' => [
                        $listQuery,
                        [
                            'wildcard' => [
                                $nameField => [
                                    'value' => '*' . addcslashes($term, '\\*?') . '*',
                                    'case_insensitive' => true,
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [
                        ['term' => [$nameField => self::UNKNOWN]],
                    ],
                ],
            ],
            'aggregations' => [
                'people' => PeopleFacet::aggregation($title, self::SIZE),
            ],
        ];
    }

    /**
     * The people from the response, with their key in the filter (see Users::keyOf()), how many documents of the list
     * they have and their avatar, null without one: the search shows the initials then. Keys, names and avatars
     * only, no email addresses.
     *
     * @param array<string, mixed>                    $aggregations
     * @param \Closure(array<string, mixed>): ?string $avatar       the address of the avatar of a user, of size AVATAR_SIZE,
     *                                                              see Users::avatarOfUser()
     * @param \Closure(string, int): string           $keyOf        see Users::keyOf()
     *
     * @return list<array{key: string, name: string, count: int, avatar: string|null}>
     */
    public static function fromAggregations(array $aggregations, string $title, \Closure $avatar, \Closure $keyOf): array
    {
        return array_values(array_map(
            static function (array $bucket) use ($title, $avatar, $keyOf): array {
                $user = (array) PeopleFacet::user($title, $bucket);

                return [
                    'key' => $keyOf(PeopleFacet::FILTERS[$title]['system'], (int) $bucket['key']),
                    'name' => (string) ($user['name'] ?? ''),
                    'count' => (int) $bucket['doc_count'],
                    'avatar' => $avatar($user),
                ];
            },
            $aggregations['people']['buckets'] ?? []
        ));
    }
}
