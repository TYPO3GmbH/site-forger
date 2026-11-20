<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use Elastica\Exception\NotFoundException;
use Elastica\Query;

/**
 * The search finds issues and Gerrit changes, each in its own tab. Both tabs show how many each of them finds.
 *
 * Forge issues and Gerrit changes are numbered alike, so a number finds the issue and the change with it, shown
 * above the results of either tab. The number also stays a search word: it finds the issues that mention it.
 */
final class GlobalSearch
{
    public const string ISSUES = 'issues';
    public const string CHANGES = 'changes';

    /**
     * The filters of people of each tab, by their key in "filters[<key>][<name>]", see PeopleFacet: what a person is
     * to an issue and to a change. Switching the tab takes them along, see tabFilters(); the first of a tab is where
     * a person of the other tab lands.
     */
    public const array PEOPLE = [
        self::ISSUES => ['author', 'assigned_to'],
        self::CHANGES => ['owner'],
    ];

    /**
     * The filters the link to a tab takes along from the other one: its people, as the filters of people of the
     * tab, see PEOPLE. A person has the same key in both, the username, see PeopleFacet. The others belong to one
     * tab only: the tab remembers its own, see assets/js/search-tabs.js.
     *
     * @param array<mixed> $filters the filters of the other tab, as the request has them
     *
     * @return array<string, array<string, string>>
     */
    public static function tabFilters(string $tab, array $filters): array
    {
        $names = [];
        foreach (self::PEOPLE[self::ISSUES === $tab ? self::CHANGES : self::ISSUES] as $key) {
            foreach ((array) ($filters[$key] ?? []) as $name => $value) {
                if ('true' === $value) {
                    $names[(string) $name] = 'true';
                }
            }
        }

        return [] === $names ? [] : [self::PEOPLE[$tab][0] => $names];
    }

    /**
     * How many issues and changes the search words find, with the people the link to the tab takes along, see
     * tabFilters(): what the tab shows when switched to. The tab shown counts what it shows, with all its filters:
     * the page knows it. An array given to Query is the whole request.
     *
     * @param array<string, array<string, mixed>|null> $people  the query of the people of a tab, see peopleQuery()
     * @param array<string, int>                       $counted what a tab found already, the tab shown
     *
     * @return array{issues: int, changes: int}
     */
    public static function tabs(string $searchTerms, bool $includeClosed, array $people = [], array $counted = []): array
    {
        $count = static function (string $index, array $query, ?array $people): int {
            $query = null === $people ? $query : ['bool' => ['must' => [$query], 'filter' => [$people]]];

            return ElasticUtility::getIndexByName($index)->count(new Query(['query' => $query]));
        };

        return [
            self::ISSUES => $counted[self::ISSUES] ?? $count('issue', IssueSearch::query($searchTerms, $includeClosed), $people[self::ISSUES] ?? null),
            self::CHANGES => $counted[self::CHANGES] ?? $count('review', (array) ReviewLists::query(ReviewLists::SEARCH, $includeClosed, $searchTerms), $people[self::CHANGES] ?? null),
        ];
    }

    /**
     * The query of the people the link to the tab takes along from the filters of the other one, see tabFilters()
     * and PeopleFacet::query(); null without people.
     *
     * @param array<mixed> $filters the filters of the other tab, as the request has them
     *
     * @return array<string, mixed>|null
     */
    public static function peopleQuery(string $tab, array $filters, Users $users): ?array
    {
        foreach (self::tabFilters($tab, $filters) as $key => $people) {
            $title = PeopleFacet::titleOf($key);
            if (null !== $title) {
                return PeopleFacet::query($title, array_map('strval', array_keys($people)), $users);
            }
        }

        return null;
    }

    /**
     * The issue and the change with the number searched for, as they are indexed, closed ones, too.
     *
     * @return array{issue: array<string, mixed>|null, change: array<string, mixed>|null}
     */
    public static function exactMatches(string $searchTerms): array
    {
        $number = self::number($searchTerms);
        if (null === $number) {
            return ['issue' => null, 'change' => null];
        }

        return ['issue' => self::document('issue', $number), 'change' => self::document('review', $number)];
    }

    /**
     * The number the search words are, alone and with an optional "#", or null.
     */
    public static function number(string $searchTerms): ?int
    {
        if (1 !== preg_match('/^#?(?<number>[1-9]\d{0,8})$/', trim($searchTerms), $matches)) {
            return null;
        }

        return (int) $matches['number'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function document(string $type, int $id): ?array
    {
        try {
            return ElasticUtility::getIndexByName($type)->getDocument((string) $id)->getData();
        } catch (NotFoundException) {
            return null;
        }
    }
}
