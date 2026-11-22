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
use App\Elastic\Indices;
use App\People\Person;
use App\People\UserDirectory;
use App\Search\PeopleFacet;
use Elastica\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The people of the filters by their key, the id of their document in the index "user": the username, the same on
 * Forge and Gerrit; "forge:<number>" or "gerrit:<number>" while the index knows none.
 */
final class PeopleKeysTest extends TestCase
{
    private UserDirectory $users;

    protected function setUp(): void
    {
        $cache = new ArrayAdapter();
        $cache->get(UserDirectory::PEOPLE_CACHE_KEY, static fn (): array => [
            'keys' => ['forge:320' => 'benni', 'gerrit:7059' => 'benni', 'forge:41' => 'forge:41', 'gerrit:9' => 'gerrit:9', 'forge:5' => 'alex_schnitzler', 'gerrit:21249' => 'benjaminkott', 'forge:38237' => 'forge:38237'],
            'people' => [
                'benni' => ['name' => 'Benni Mack', 'names' => ['Benni Mack'], 'username' => 'benni', 'forge' => 320, 'gerrit' => 7059, 'linked' => ['forge' => [], 'gerrit' => []]],
                'forge:41' => ['name' => 'Not asked for yet', 'names' => ['Not asked for yet'], 'username' => null, 'forge' => 41, 'gerrit' => null, 'linked' => ['forge' => [], 'gerrit' => []]],
                'gerrit:9' => ['name' => 'Without username', 'names' => ['Without username'], 'username' => null, 'forge' => null, 'gerrit' => 9, 'linked' => ['forge' => [], 'gerrit' => []]],
                'alex_schnitzler' => ['name' => 'Alexander Schnitzler', 'names' => ['Alexander Schnitzler'], 'username' => 'alex_schnitzler', 'forge' => 5, 'gerrit' => null, 'linked' => ['forge' => [], 'gerrit' => []]],
                // One person in two documents, until Forge is asked for the one of the issues
                'benjaminkott' => ['name' => 'Benjamin Kott', 'names' => ['Benjamin Kott'], 'username' => 'benjaminkott', 'forge' => null, 'gerrit' => 21249, 'linked' => ['forge' => [], 'gerrit' => []]],
                'forge:38237' => ['name' => 'Benjamin Kott', 'names' => ['Benjamin Kott'], 'username' => null, 'forge' => 38237, 'gerrit' => null, 'linked' => ['forge' => [], 'gerrit' => []]],
            ],
            'aliases' => [],
        ]);
        $this->users = new UserDirectory(new Indices(new Client()), $cache, new NullLogger());
    }

    public function testAPersonIsTheUsernameOnForgeAndGerritAlike(): void
    {
        self::assertSame('benni', $this->users->keyOf('forge', 320));
        self::assertSame('benni', $this->users->keyOf('gerrit', 7059));
        self::assertSame('forge:41', $this->users->keyOf('forge', 41));
        // Unknown to the index: the number
        self::assertSame('forge:99', $this->users->keyOf('forge', 99));

        self::assertSame('benni', $this->users->keyOfPerson(new Person('Benni Mack', forgeId: 320)));
        self::assertSame('benni', $this->users->keyOfPerson(new Person('Benni', gerritId: 7059)));
        // A change of before the number of its owner: the name, as the links had it
        self::assertSame(
            'Benni_Mack',
            $this->users->keyOfPerson(new Person('Benni Mack', email: 'benni@example.org')),
        );
    }

    public function testTheKeysLeadToThePeople(): void
    {
        self::assertEquals(
            new Person('Benni Mack', forgeId: 320, gerritId: 7059, username: 'benni'),
            $this->users->personOf('benni'),
        );
        // A key of a number the index wrote under the username since
        self::assertSame('benni', $this->users->personOf('forge:320')?->username);
        self::assertEquals(new Person('', forgeId: 99), $this->users->personOf('forge:99'));
        // A username has a "_" of its own
        self::assertSame(5, $this->users->personOf('alex_schnitzler')?->forgeId);
        // A name, as the links had them before
        self::assertNull($this->users->personOf('Benni_Mack'));
    }

    public function testTheIssuesOfAPersonByTheNumberOnForge(): void
    {
        $query = PeopleFacet::query('Author', ['benni', 'forge:41', 'Oli_Bartsch'], $this->users);

        self::assertSame(['bool' => ['should' => [
            ['terms' => ['author.id' => [320, 41]]],
            ['terms' => ['author.name' => ['Oli Bartsch']]],
        ], 'minimum_should_match' => 1]], $query);
        self::assertSame(
            ['terms' => ['assigned_to.id' => [5]]],
            $this->peopleQuery('Assigned to', ['alex_schnitzler'])['bool']['should'][0],
        );
        self::assertNull(PeopleFacet::query('Author', [], $this->users));
        // Known from Gerrit only, Forge not asked yet: by the number of the document of the issues of the same name
        self::assertSame([38237], $this->users->numbersOf('benjaminkott', 'forge'));
        self::assertSame(
            ['terms' => ['author.id' => [38237]]],
            $this->peopleQuery('Author', ['benjaminkott'])['bool']['should'][0],
        );
        // And the old links by the name
        self::assertSame([320], $this->users->numbersOf('Benni_Mack', 'forge'));
        // Nobody of the name has issues
        self::assertEquals(['match_none' => new \stdClass()], PeopleFacet::query('Author', ['gerrit:9'], $this->users));
    }

    public function testTheChangesOfAPersonByTheNumberOnGerritAndTheUsername(): void
    {
        $query = PeopleFacet::query('Owner', ['benni'], $this->users);

        self::assertSame(['bool' => ['should' => [
            ['terms' => ['owner_id' => [7059]]],
            ['terms' => ['owner_username' => ['benni']]],
            // The changes imported before the number of their owner, by the name
            ['bool' => ['must_not' => [['exists' => ['field' => 'owner_id']]], 'filter' => [['terms' => ['owner' => ['Benni Mack']]]]]],
        ], 'minimum_should_match' => 1]], $query);
        // A person of Forge only is found by the username, too
        self::assertSame(
            ['terms' => ['owner_username' => ['alex_schnitzler']]],
            $this->peopleQuery('Owner', ['alex_schnitzler'])['bool']['should'][0],
        );
        // A number of Forge has no changes
        self::assertEquals(['match_none' => new \stdClass()], PeopleFacet::query('Owner', ['forge:99'], $this->users));
    }

    public function testTheBucketsAreThePeopleByTheirKeys(): void
    {
        $owner = PeopleFacet::keyed(
            Bucket::facetOf(['buckets' => [['key' => 7059, 'doc_count' => 3], ['key' => 9, 'doc_count' => 1]]]),
            'Owner',
            $this->users,
        );

        self::assertSame(
            ['benni', 'gerrit:9'],
            $owner->map(static fn (Bucket $bucket): string => $bucket->key)->toArray(),
        );
        self::assertSame([320 => 'benni', 41 => 'forge:41'], PeopleFacet::selection('Author', ['benni', 'forge:41', 'Oli_Bartsch', 'benni'], $this->users));
        self::assertSame([7059 => 'benni'], PeopleFacet::selection('Owner', ['benni', 'forge:41'], $this->users));
        // A person selected by the username of Gerrit is ticked in the issues, where the index has its number apart
        $selection = PeopleFacet::selection('Author', ['benjaminkott'], $this->users);
        $author = PeopleFacet::keyed(
            Bucket::facetOf(['buckets' => [['key' => 38237, 'doc_count' => 2], ['key' => 41, 'doc_count' => 1]]]),
            'Author',
            $this->users,
            $selection,
        );
        self::assertSame(
            ['benjaminkott', 'forge:41'],
            $author->map(static fn (Bucket $bucket): string => $bucket->key)->toArray(),
        );
        self::assertSame('Assigned to', PeopleFacet::titleOf('assigned_to'));
        self::assertNull(PeopleFacet::titleOf('status'));
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private function peopleQuery(string $title, array $keys): array
    {
        $query = PeopleFacet::query($title, $keys, $this->users);
        self::assertNotNull($query);

        return $query;
    }
}
