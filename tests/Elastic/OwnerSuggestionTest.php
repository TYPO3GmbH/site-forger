<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\OwnerSuggestion;
use PHPUnit\Framework\TestCase;

final class OwnerSuggestionTest extends TestCase
{
    private const array LIST_QUERY = ['bool' => ['must' => [['term' => ['mergeable' => 'no']]]]];

    public function testTooShortTermAsksNothing(): void
    {
        self::assertNull(OwnerSuggestion::request('', self::LIST_QUERY));
        self::assertNull(OwnerSuggestion::request(' b ', self::LIST_QUERY));
    }

    public function testFindsOwnersOfTheListContainingTheTermInAnyCase(): void
    {
        $request = OwnerSuggestion::request(' benni ', self::LIST_QUERY);

        self::assertSame(0, $request['size']);
        self::assertSame(self::LIST_QUERY, $request['query']['bool']['filter'][0]);
        self::assertSame(
            ['value' => '*benni*', 'case_insensitive' => true],
            $request['query']['bool']['filter'][1]['wildcard']['owner']
        );
        self::assertSame([['term' => ['owner' => 'Unknown']]], $request['query']['bool']['must_not']);
        self::assertSame(['field' => 'owner', 'size' => OwnerSuggestion::SIZE], $request['aggregations']['owners']['terms']);
        self::assertSame(['includes' => ['ownerEmail']], $request['aggregations']['owners']['aggregations']['change']['top_hits']['_source']);
    }

    public function testWildcardsInTheTermAreTakenLiterally(): void
    {
        $request = OwnerSuggestion::request('a*b?c\\', self::LIST_QUERY);

        self::assertSame('*a\\*b\\?c\\\\*', $request['query']['bool']['filter'][1]['wildcard']['owner']['value']);
    }

    public function testReadsNamesCountsAndAvatarsWithoutEmailAddresses(): void
    {
        $aggregations = ['owners' => ['buckets' => [
            ['key' => 'Benni Mack', 'doc_count' => 16, 'change' => ['hits' => ['hits' => [['_source' => ['ownerEmail' => 'Benni@Example.org ']]]]]],
            ['key' => 'Somebody', 'doc_count' => 1, 'change' => ['hits' => ['hits' => [['_source' => ['ownerEmail' => 'Unknown']]]]]],
        ]]];

        $owners = OwnerSuggestion::fromAggregations($aggregations);

        self::assertSame(
            [
                ['name' => 'Benni Mack', 'changes' => 16, 'avatar' => 'https://www.gravatar.com/avatar/' . md5('benni@example.org') . '?s=48&d=mp&r=g'],
                ['name' => 'Somebody', 'changes' => 1, 'avatar' => null],
            ],
            $owners
        );
        self::assertStringNotContainsString('@', (string) json_encode($owners));
        self::assertSame([], OwnerSuggestion::fromAggregations([]));
    }
}
