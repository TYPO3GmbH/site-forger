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
 * The latest name and username of each account on Gerrit in the activity stream: for the users without the name,
 * see App\Import\GerritNames, and to tell whose an account is, see App\Import\GerritAccountRepair.
 */
final class GerritNameQuery
{
    public const string ACCOUNTS = 'accounts';
    public const string LATEST = 'latest';

    /**
     * @param list<int> $gerritIds
     *
     * @return array<string, mixed>
     */
    public static function request(array $gerritIds): array
    {
        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => ['bool' => ['filter' => [
                ['terms' => ['author.gerrit_id' => $gerritIds]],
                ['term' => ['bot' => false]],
            ]]],
            'aggs' => [self::ACCOUNTS => [
                'terms' => ['field' => 'author.gerrit_id', 'size' => max(1, count($gerritIds))],
                'aggs' => [self::LATEST => ['top_hits' => [
                    'size' => 1,
                    'sort' => [['date' => 'desc']],
                    '_source' => ['author.name', 'author.username'],
                ]]],
            ]],
        ];
    }
}
