<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

/**
 * How much each account did, all the time: the events and the first and the last of them, per account on Forge and
 * on Gerrit. For the admins who link accounts to a person, see App\Controller\PeopleController. AccountActivity
 * reads the answer.
 */
final class AccountActivityQuery
{
    public const string FORGE = 'forge';
    public const string GERRIT = 'gerrit';
    public const string FIRST = 'first';
    public const string LAST = 'last';

    /**
     * @param list<int> $forgeIds
     * @param list<int> $gerritIds
     *
     * @return array<string, mixed>
     */
    public static function request(array $forgeIds, array $gerritIds): array
    {
        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => ['bool' => [
                'should' => [
                    ['terms' => ['author.forge_id' => $forgeIds]],
                    ['terms' => ['author.gerrit_id' => $gerritIds]],
                ],
                'minimum_should_match' => 1,
            ]],
            'aggs' => [
                self::FORGE => self::perAccount('author.forge_id', $forgeIds),
                self::GERRIT => self::perAccount('author.gerrit_id', $gerritIds),
            ],
        ];
    }

    /**
     * @param list<int> $numbers
     *
     * @return array<string, mixed>
     */
    private static function perAccount(string $field, array $numbers): array
    {
        return [
            'terms' => [
                'field' => $field,
                'include' => [] === $numbers ? [0] : $numbers,
                'size' => max(1, count($numbers)),
            ],
            'aggs' => [
                self::FIRST => ['min' => ['field' => 'date']],
                self::LAST => ['max' => ['field' => 'date']],
            ],
        ];
    }
}
