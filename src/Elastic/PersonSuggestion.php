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
 * People in a list whose name contains what was typed, for the search in a filter of people:
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
     * The search request for the people in the list whose name contains the term, any case, those with the most first.
     *
     * @param array<string, mixed> $listQuery   the query of the list, like ReviewLists::query()
     * @param string               $nameField   a keyword field with the name, like "owner"
     * @param string               $avatarField the field the avatar is found by, like "ownerEmail" or "author.id"
     *
     * @return array<string, mixed>|null null if the term is too short
     */
    public static function request(string $term, array $listQuery, string $nameField, string $avatarField): ?array
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
                'people' => [
                    'terms' => [
                        'field' => $nameField,
                        'size' => self::SIZE,
                    ],
                    // The latest document of the person that has the field, for the avatar
                    'aggregations' => [
                        'withAvatar' => [
                            'filter' => ['exists' => ['field' => $avatarField]],
                            'aggregations' => [
                                'latest' => [
                                    'top_hits' => [
                                        'size' => 1,
                                        '_source' => ['includes' => [$avatarField]],
                                        'sort' => [['updated_on' => 'desc']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The people from the response, with how many documents of the list they have and their avatar.
     *
     * The field only gives the address of the avatar, an email address does not leave the application.
     *
     * @param array<string, mixed>                  $aggregations
     * @param (\Closure(mixed): (string|null))|null $avatar       the address of the avatar for the value of the
     *                                                            field, of size AVATAR_SIZE; without, the field is
     *                                                            an email
     *
     * @return list<array{name: string, count: int, avatar: string|null}>
     */
    public static function fromAggregations(array $aggregations, string $avatarField, ?\Closure $avatar = null): array
    {
        return array_values(array_map(
            static function (array $bucket) use ($avatarField, $avatar): array {
                $source = $bucket['withAvatar']['latest']['hits']['hits'][0]['_source'] ?? [];
                $value = self::valueAt($source, $avatarField);

                return [
                    'name' => (string) $bucket['key'],
                    'count' => (int) $bucket['doc_count'],
                    'avatar' => null === $avatar ? self::emailAvatar((string) $value) : (null === $value ? null : $avatar($value)),
                ];
            },
            $aggregations['people']['buckets'] ?? []
        ));
    }

    /**
     * The avatar of an email, null if it is none ("Unknown").
     */
    private static function emailAvatar(string $email): ?string
    {
        return str_contains($email, '@') ? AvatarUtility::getAvatarUrl($email, self::AVATAR_SIZE) : null;
    }

    /**
     * The value at a dotted path like "assigned_to.id".
     *
     * @param array<string, mixed> $source
     */
    private static function valueAt(array $source, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($source) || !array_key_exists($key, $source)) {
                return null;
            }
            $source = $source[$key];
        }

        return $source;
    }
}
