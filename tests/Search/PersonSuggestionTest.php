<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Elastic\Bucket;
use App\People\Person;
use App\Search\FacetSelection;
use App\Search\PeopleFacet;
use App\Search\PersonSuggestion;
use PHPUnit\Framework\TestCase;

final class PersonSuggestionTest extends TestCase
{
    private const array LIST_QUERY = ['bool' => ['must' => [['term' => ['mergeable' => 'no']]]]];

    public function testTooShortTermAsksNothing(): void
    {
        self::assertNull(PersonSuggestion::request('', self::LIST_QUERY, 'Owner'));
        self::assertNull(PersonSuggestion::request(' b ', self::LIST_QUERY, 'Owner'));
    }

    public function testFindsPeopleOfTheListContainingTheTermInAnyCase(): void
    {
        $request = PersonSuggestion::request(' benni ', self::LIST_QUERY, 'Assigned to');
        self::assertNotNull($request);

        self::assertSame(0, $request['size']);
        self::assertSame(self::LIST_QUERY, $request['query']['bool']['filter'][0]);
        self::assertSame(
            ['value' => '*benni*', 'case_insensitive' => true],
            $request['query']['bool']['filter'][1]['wildcard']['assigned_to.name.keyword']
        );
        self::assertSame([['term' => ['assigned_to.name.keyword' => 'Unknown']]], $request['query']['bool']['must_not']);
        // The same aggregation as the filter, with more people
        self::assertSame(PeopleFacet::aggregation('Assigned to', PersonSuggestion::SIZE), $request['aggregations']['people']);
    }

    public function testWildcardsInTheTermAreTakenLiterally(): void
    {
        $request = PersonSuggestion::request('a*b?c\\', self::LIST_QUERY, 'Owner');
        self::assertNotNull($request);

        self::assertSame('*a\\*b\\?c\\\\*', $request['query']['bool']['filter'][1]['wildcard']['owner']['value']);
    }

    public function testReadsNamesCountsAndAvatarsOfTheUsers(): void
    {
        $latest = static fn (array $source): array => ['user' => ['hits' => ['hits' => [['_source' => $source]]]]];
        // By the number of the owner, the name from the latest change
        $aggregations = ['people' => ['buckets' => [
            ['key' => 52917, 'doc_count' => 16] + $latest(['owner' => 'Ingo Hollmann', 'owner_id' => 52917, 'ownerEmail' => 'ingo@example.org']),
            ['key' => 7, 'doc_count' => 1] + $latest(['owner' => 'Somebody', 'owner_id' => 7, 'ownerEmail' => 'Unknown']),
        ]]];
        $keyOf = static fn (string $system, int $number): string => 52917 === $number ? 'ingoholl' : $system . ':' . $number;
        $asked = [];
        $avatar = static function (?Person $person) use (&$asked): ?string {
            $asked[] = $person;

            return null !== $person?->gerritId ? 'https://www.gravatar.com/avatar/abc?s=48&d=mp&r=g' : null;
        };

        $people = PersonSuggestion::fromBuckets(Bucket::listOf($aggregations['people']), 'Owner', $avatar, $keyOf);

        // The key the checkbox of the person has: the username, the number while the index knows none
        self::assertSame([
            ['key' => 'ingoholl', 'name' => 'Ingo Hollmann', 'count' => 16, 'avatar' => 'https://www.gravatar.com/avatar/abc?s=48&d=mp&r=g'],
            ['key' => 'gerrit:7', 'name' => 'Somebody', 'count' => 1, 'avatar' => 'https://www.gravatar.com/avatar/abc?s=48&d=mp&r=g'],
        ], $people);
        // The people of the filter: name, number and email
        self::assertEquals([
            new Person('Ingo Hollmann', gerritId: 52917, email: 'ingo@example.org'),
            new Person('Somebody', gerritId: 7, email: 'Unknown'),
        ], $asked);
        self::assertStringNotContainsString('@', (string) json_encode($people));
        self::assertSame([], PersonSuggestion::fromBuckets(Bucket::listOf(null), 'Owner', $avatar, $keyOf));
    }

    public function testThePeopleOfIssuesAndChangesAreFoundAlike(): void
    {
        $author = PeopleFacet::aggregation('Author');
        $owner = PeopleFacet::aggregation('Owner');

        // By the number: two people of one name are two
        self::assertSame(['field' => 'author.id', 'size' => FacetSelection::SIZE, 'shard_size' => FacetSelection::SHARD_SIZE], $author['terms']);
        self::assertSame(['field' => 'owner_id', 'size' => FacetSelection::SIZE, 'shard_size' => FacetSelection::SHARD_SIZE], $owner['terms']);
        self::assertSame(['author.id', 'author.name'], $author['aggs']['user']['top_hits']['_source']['includes']);
        self::assertSame(['owner_id', 'ownerEmail', 'owner'], $owner['aggs']['user']['top_hits']['_source']['includes']);
        $latest = ['hits' => ['hits' => [['_source' => ['author' => ['id' => 320, 'name' => 'Benni Mack']]]]]];
        self::assertEquals(
            new Person('Benni Mack', forgeId: 320),
            PeopleFacet::person('Author', Bucket::fromArray(['key' => 320, 'user' => $latest])),
        );
        self::assertEquals(
            new Person('Benni Mack'),
            PeopleFacet::person('Author', Bucket::fromArray(['key' => 'Benni Mack'])),
        );
        self::assertNull(PeopleFacet::person('Tags', Bucket::fromArray(['key' => 'cache'])));
        // The selected people of a filter come with their latest document too
        self::assertSame($author['aggs'], FacetSelection::withSelected(['Author' => $author], 'Author', [320])['Author (selected)']['aggs']);
    }
}
