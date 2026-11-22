<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\ReviewStatusEnum;

/**
 * The people with the most activity in a time window, for the leaderboard of the admins: all events, and the events
 * of each key figure, counted as StreamFigureEnum counts them, so the numbers equal the statistics. Without the
 * events of tools. Beside them the lines of the changes merged in the window, of the review index, see
 * linesRequest().
 *
 * It counts per account, on Forge and on Gerrit, and by name only the events without an account. Leaderboards sums
 * the accounts up under the TYPO3 username, never under a name: two people of one name stay two.
 */
final class LeaderboardQuery
{
    public const string FORGE = 'forge';
    public const string GERRIT = 'gerrit';
    public const string NAMES = 'names';
    public const string LATEST = 'latest';
    public const string OWNERS = 'owners';
    public const string INSERTIONS = 'insertions';
    public const string DELETIONS = 'deletions';

    /**
     * The places the board shows.
     */
    public const int SIZE = 50;

    /**
     * The accounts it asks for per system: all of them, a year has about 550 people. With all accounts the sums are
     * whole, whatever the order.
     */
    public const int ACCOUNTS = 2000;

    /**
     * All events and the events of each figure in the window, per account on Forge, per account on Gerrit, and per
     * name for the events without an account.
     *
     * @return array<string, mixed>
     */
    public static function request(StreamWindow $window): array
    {
        $withoutAccount = ['bool' => ['must_not' => [
            ['exists' => ['field' => 'author.forge_id']],
            ['exists' => ['field' => 'author.gerrit_id']],
        ]]];

        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => StreamQuery::query(new StreamFilter(window: $window)),
            'aggs' => [
                self::FORGE => self::perPerson('author.forge_id'),
                self::GERRIT => self::perPerson('author.gerrit_id'),
                self::NAMES => [
                    'filter' => $withoutAccount,
                    'aggs' => [self::NAMES => self::perPerson('author.name')],
                ],
            ],
        ];
    }

    /**
     * The lines inserted and deleted by the changes merged in the window, per owner, for the review index. A change
     * counts with its last patch set, as Gerrit shows it.
     *
     * @return array<string, mixed>
     */
    public static function linesRequest(StreamWindow $window): array
    {
        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => ['bool' => ['filter' => [
                ['term' => ['reviewstatus' => ReviewStatusEnum::MERGED->value]],
                $window->query('closed_on'),
            ]]],
            'aggs' => [self::OWNERS => [
                'terms' => ['field' => 'owner_id', 'size' => self::ACCOUNTS],
                'aggs' => [
                    self::INSERTIONS => ['sum' => ['field' => 'insertions']],
                    self::DELETIONS => ['sum' => ['field' => 'deletions']],
                    self::LATEST => ['top_hits' => [
                        'size' => 1,
                        'sort' => [['closed_on' => 'desc']],
                        '_source' => ['owner', 'owner_id', 'owner_username'],
                    ]],
                ],
            ]],
        ];
    }

    /**
     * The events of each value of the field, all and per figure, with the latest event: who the person is.
     *
     * @return array<string, mixed>
     */
    private static function perPerson(string $field): array
    {
        $figures = [];
        foreach (StreamInsightsQuery::figures() as $key => $filter) {
            $figures[$key] = ['filter' => $filter];
        }

        return [
            'terms' => ['field' => $field, 'size' => self::ACCOUNTS],
            'aggs' => [
                ...$figures,
                self::LATEST => ['top_hits' => [
                    'size' => 1,
                    'sort' => [['date' => 'desc']],
                    '_source' => ['author', 'date'],
                ]],
            ],
        ];
    }
}
