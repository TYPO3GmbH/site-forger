<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Charts\TimeSeries;
use App\Enum\StreamFigureEnum;
use App\Enum\StreamKindEnum;

/**
 * The requests about people: who is active by a part of the name, and what the people a user follows did lately.
 * App\Stream\StreamPeople reads the answers.
 */
final class PeopleQuery
{
    public const string PEOPLE = 'people';

    public const string LATEST = 'latest';

    public const string WEEKS = 'weeks';

    public const string REVIEWS = 'reviews';

    public const string PATCHES = 'patches';

    public const string ISSUES = 'issues';

    public const string COMMENTS = 'comments';

    /**
     * Fewer characters match too many people to be of help.
     */
    public const int MIN_LENGTH = 2;

    public const int SIZE = 10;

    /**
     * The people of the activity whose name contains the term or whose username starts with it, any case, the
     * most active first. Each comes with the latest event, which names the person by its numbers. An event may not
     * know the username of its author: the numbers of the accounts with the username, from the index "user", find
     * the person too.
     *
     * @param list<int> $forgeIds  the accounts on Forge whose username starts with the term
     * @param list<int> $gerritIds the accounts on Gerrit whose username starts with the term
     *
     * @return array<string, mixed>|null null if the term is too short
     */
    public static function search(string $term, array $forgeIds = [], array $gerritIds = []): ?array
    {
        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return null;
        }

        return [
            'size' => 0,
            'query' => ['bool' => [
                'filter' => [['term' => ['bot' => false]]],
                'should' => [
                    NameSearch::contains('author.name', $term),
                    ['prefix' => ['author.username' => ['value' => $term]]],
                    ...([] === $forgeIds ? [] : [['terms' => ['author.forge_id' => $forgeIds]]]),
                    ...([] === $gerritIds ? [] : [['terms' => ['author.gerrit_id' => $gerritIds]]]),
                ],
                'minimum_should_match' => 1,
            ]],
            'aggs' => [self::PEOPLE => [
                'terms' => ['field' => 'author.name', 'size' => self::SIZE],
                'aggs' => [self::LATEST => self::latest()],
            ]],
        ];
    }

    /**
     * The events of each person in the window, without the events of tools: per week, and as the figures of the
     * statistics count them, see StreamFigureEnum: the reviews, the new changes and patch sets, the issues created and
     * the comments on issues.
     *
     * @param array<string, array{forge: list<int>, gerrit: list<int>}> $people the numbers by the key of the person
     *
     * @return array<string, mixed>
     */
    public static function activity(array $people, StreamWindow $window): array
    {
        $filters = [];
        foreach ($people as $key => $numbers) {
            $filters[$key] = self::eventsOf($numbers['forge'], $numbers['gerrit']);
        }
        $utc = new \DateTimeZone('UTC');

        return [
            'size' => 0,
            'query' => ['bool' => ['filter' => [
                ['term' => ['bot' => false]],
                ['range' => ['date' => [
                    'gte' => $window->from->setTimezone($utc)->format('Y/m/d H:i:s'),
                    'lt' => $window->until->setTimezone($utc)->format('Y/m/d H:i:s'),
                ]]],
            ]]],
            'aggs' => [self::PEOPLE => [
                'filters' => ['filters' => [] === $filters ? ['none' => ['match_none' => new \stdClass()]] : $filters],
                'aggs' => [
                    self::WEEKS => TimeSeries::histogram('date', TimeSeries::WEEK, $window->from, $window->until),
                    self::REVIEWS => ['filter' => self::figures(StreamFigureEnum::REVIEWS)],
                    self::PATCHES => [
                        'filter' => self::figures(StreamFigureEnum::NEW_CHANGES, StreamFigureEnum::PATCH_SETS),
                    ],
                    self::ISSUES => ['filter' => self::figures(StreamFigureEnum::ISSUES_CREATED)],
                    self::COMMENTS => ['filter' => self::figures(StreamFigureEnum::ISSUE_COMMENTS)],
                ],
            ]],
        ];
    }

    /**
     * The events the figures of the statistics count together.
     *
     * @return array<string, mixed>
     */
    private static function figures(StreamFigureEnum ...$figures): array
    {
        return ['bool' => [
            'should' => array_map(
                static fn (StreamFigureEnum $figure): array => ['bool' => ['filter' => [
                    ['term' => ['source' => $figure->source()->value]],
                    ['terms' => ['kind' => array_map(
                        static fn (StreamKindEnum $kind): string => $kind->value,
                        $figure->kinds(),
                    )]],
                ]]],
                $figures,
            ),
            'minimum_should_match' => 1,
        ]];
    }

    /**
     * @param list<int> $forgeIds
     * @param list<int> $gerritIds
     *
     * @return array<string, mixed>
     */
    private static function eventsOf(array $forgeIds, array $gerritIds): array
    {
        $should = [];
        if ([] !== $forgeIds) {
            $should[] = ['terms' => ['author.forge_id' => $forgeIds]];
        }
        if ([] !== $gerritIds) {
            $should[] = ['terms' => ['author.gerrit_id' => $gerritIds]];
        }

        return [] === $should
            ? ['match_none' => new \stdClass()]
            : ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function latest(): array
    {
        return ['top_hits' => ['size' => 1, 'sort' => [['date' => 'desc']], '_source' => ['author', 'date']]];
    }
}
