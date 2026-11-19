<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use T3G\Bundle\TemplateBundle\Utility\AvatarUtility;

/**
 * Owners of the changes in a review list whose name contains what was typed, for the search in its "Owner" filter.
 *
 * There are thousands of owners, so the page does not hold them all but asks for the ones that match.
 * It asks with the query of the list, see ReviewLists, so it suggests the owners and numbers the filter has.
 */
final class OwnerSuggestion
{
    /**
     * Fewer characters match too many owners to be of help.
     */
    public const int MIN_LENGTH = 2;

    public const int SIZE = 15;

    /**
     * Twice the size the avatar is shown in, for sharp screens.
     */
    private const int AVATAR_SIZE = 48;

    /**
     * Owner of changes the import does not know the author of.
     */
    private const string UNKNOWN = 'Unknown';

    /**
     * The search request for the owners of the changes in the list whose name contains the term, any case,
     * those with the most first.
     *
     * @param array<string, mixed> $listQuery the query of the list, see ReviewLists::query()
     *
     * @return array<string, mixed>|null null if the term is too short
     */
    public static function request(string $term, array $listQuery): ?array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return null;
        }

        return [
            'size' => 0,
            'query' => [
                'bool' => [
                    'filter' => [
                        $listQuery,
                        [
                            'wildcard' => [
                                'owner' => [
                                    'value' => '*' . addcslashes($term, '\\*?') . '*',
                                    'case_insensitive' => true,
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [
                        ['term' => ['owner' => self::UNKNOWN]],
                    ],
                ],
            ],
            'aggregations' => [
                'owners' => [
                    'terms' => [
                        'field' => 'owner',
                        'size' => self::SIZE,
                    ],
                    // One change of the owner, for the address of the avatar
                    'aggregations' => [
                        'change' => [
                            'top_hits' => [
                                'size' => 1,
                                '_source' => ['includes' => ['ownerEmail']],
                                'sort' => [['updated_on' => 'desc']],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The owners from the response, with how many changes of the list they own and their avatar.
     *
     * The email address only gives the address of the avatar, it does not leave the application.
     *
     * @param array<string, mixed> $aggregations
     *
     * @return list<array{name: string, changes: int, avatar: string|null}>
     */
    public static function fromAggregations(array $aggregations): array
    {
        return array_values(array_map(
            static function (array $bucket): array {
                $email = (string) ($bucket['change']['hits']['hits'][0]['_source']['ownerEmail'] ?? '');

                return [
                    'name' => (string) $bucket['key'],
                    'changes' => (int) $bucket['doc_count'],
                    'avatar' => str_contains($email, '@') ? AvatarUtility::getAvatarUrl($email, self::AVATAR_SIZE) : null,
                ];
            },
            $aggregations['owners']['buckets'] ?? []
        ));
    }
}
