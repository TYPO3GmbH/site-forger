<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\PersonSuggestion;
use PHPUnit\Framework\TestCase;

final class PersonSuggestionTest extends TestCase
{
    private const array LIST_QUERY = ['bool' => ['must' => [['term' => ['mergeable' => 'no']]]]];

    public function testTooShortTermAsksNothing(): void
    {
        self::assertNull(PersonSuggestion::request('', self::LIST_QUERY, 'owner', 'ownerEmail'));
        self::assertNull(PersonSuggestion::request(' b ', self::LIST_QUERY, 'owner', 'ownerEmail'));
    }

    public function testFindsPeopleOfTheListContainingTheTermInAnyCase(): void
    {
        $request = PersonSuggestion::request(' benni ', self::LIST_QUERY, 'assigned_to.name.keyword', 'assigned_to.email');

        self::assertSame(0, $request['size']);
        self::assertSame(self::LIST_QUERY, $request['query']['bool']['filter'][0]);
        self::assertSame(
            ['value' => '*benni*', 'case_insensitive' => true],
            $request['query']['bool']['filter'][1]['wildcard']['assigned_to.name.keyword']
        );
        self::assertSame([['term' => ['assigned_to.name.keyword' => 'Unknown']]], $request['query']['bool']['must_not']);
        $people = $request['aggregations']['people'];
        self::assertSame(['field' => 'assigned_to.name.keyword', 'size' => PersonSuggestion::SIZE], $people['terms']);
        self::assertSame(['exists' => ['field' => 'assigned_to.email']], $people['aggregations']['withEmail']['filter']);
        self::assertSame(['includes' => ['assigned_to.email']], $people['aggregations']['withEmail']['aggregations']['latest']['top_hits']['_source']);
    }

    public function testWildcardsInTheTermAreTakenLiterally(): void
    {
        $request = PersonSuggestion::request('a*b?c\\', self::LIST_QUERY, 'owner', 'ownerEmail');

        self::assertSame('*a\\*b\\?c\\\\*', $request['query']['bool']['filter'][1]['wildcard']['owner']['value']);
    }

    public function testReadsNamesCountsAndAvatarsWithoutEmailAddresses(): void
    {
        $withEmail = static fn (array $source): array => ['withEmail' => ['latest' => ['hits' => ['hits' => [['_source' => $source]]]]]];
        $aggregations = ['people' => ['buckets' => [
            ['key' => 'Benni Mack', 'doc_count' => 16] + $withEmail(['assigned_to' => ['email' => 'Benni@Example.org ']]),
            ['key' => 'Somebody', 'doc_count' => 1] + $withEmail(['assigned_to' => ['email' => 'Unknown']]),
            ['key' => 'A group', 'doc_count' => 2, 'withEmail' => ['latest' => ['hits' => ['hits' => []]]]],
        ]]];

        $people = PersonSuggestion::fromAggregations($aggregations, 'assigned_to.email');

        self::assertSame(
            [
                ['name' => 'Benni Mack', 'count' => 16, 'avatar' => 'https://www.gravatar.com/avatar/' . md5('benni@example.org') . '?s=48&d=mp&r=g'],
                ['name' => 'Somebody', 'count' => 1, 'avatar' => null],
                ['name' => 'A group', 'count' => 2, 'avatar' => null],
            ],
            $people
        );
        self::assertStringNotContainsString('@', (string) json_encode($people));
        self::assertSame([], PersonSuggestion::fromAggregations([], 'ownerEmail'));
    }
}
