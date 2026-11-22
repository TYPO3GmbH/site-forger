<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\People;

use App\Elastic\Indices;
use App\People\UserDirectory;
use Elastica\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PersonLinksTest extends TestCase
{
    public function testTheAccountsLinkedCountAsThePersonsEverywhere(): void
    {
        $users = self::directory([
            // Linked to the person: a document of Forge without a username, another TYPO3 account, and an account
            // on Forge the index has no document of
            'forge:128' => 'just2b',
            'old_just2b' => 'just2b',
            'forge:999' => 'just2b',
            // A link to a person the index does not know does nothing
            'gerrit:5' => 'nobody',
        ]);

        self::assertSame('just2b', $users->keyOf('forge', 128));
        self::assertSame('just2b', $users->keyOf('forge', 33777));
        self::assertSame('just2b', $users->keyOf('forge', 999));
        self::assertSame('gerrit:5', $users->keyOf('gerrit', 5));
        self::assertSame([128, 33777, 999], $users->numbersOf('just2b', 'forge'));
        self::assertSame([7342], $users->numbersOf('just2b', 'gerrit'));
        // A key of an account linked leads to all accounts of the person
        self::assertSame([128, 33777, 999], $users->numbersOf('forge:128', 'forge'));
        self::assertSame(
            ['old_just2b', 'forge:128', 'forge:33777', 'forge:999', 'gerrit:7342'],
            $users->keysOf('old_just2b'),
        );
        $person = $users->personOf('old_just2b');
        self::assertNotNull($person);
        self::assertSame(['Georg Ringer', 'just2b'], [$person->name, $person->username]);
    }

    public function testWithoutLinksThePeopleStayApart(): void
    {
        $users = self::directory([]);

        self::assertSame('forge:128', $users->keyOf('forge', 128));
        self::assertSame([128], $users->numbersOf('forge:128', 'forge'));
        self::assertSame([7342], $users->numbersOf('just2b', 'gerrit'));
        self::assertNull($users->personOf('forge:128')?->username);
    }

    /**
     * @param array<string, string> $links
     */
    private static function directory(array $links): UserDirectory
    {
        $entry = static fn (string $name, ?string $username, ?int $forge, ?int $gerrit): array => [
            'name' => $name,
            'names' => [$name],
            'username' => $username,
            'forge' => $forge,
            'gerrit' => $gerrit,
            'linked' => ['forge' => [], 'gerrit' => []],
        ];
        $people = UserDirectory::linked([
            'keys' => ['gerrit:7342' => 'just2b', 'forge:128' => 'forge:128', 'forge:33777' => 'old_just2b'],
            'people' => [
                'just2b' => $entry('Georg Ringer', 'just2b', null, 7342),
                'forge:128' => $entry('Georg Ringer', null, 128, null),
                'old_just2b' => $entry('Georg Ringer', 'old_just2b', 33777, null),
            ],
            'aliases' => [],
        ], $links);
        $cache = new ArrayAdapter();
        $cache->get(UserDirectory::PEOPLE_CACHE_KEY, static fn (): array => $people);

        return new UserDirectory(new Indices(new Client()), $cache, new NullLogger());
    }
}
