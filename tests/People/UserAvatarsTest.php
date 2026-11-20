<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\People;

use App\People\User;
use App\People\UserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The avatars of the pages, by the numbers of the people on Forge and Gerrit, see UserDirectory::avatarMap().
 */
final class UserAvatarsTest extends TestCase
{
    public function testAPersonForgeWasNotAskedForYetHasTheAvatarOfTheOneOfTheSameName(): void
    {
        $avatars = UserDirectory::avatarMap([
            new User(null, 'Benni Mack', 'benni', 7059, ['benni@example.org']),
            new User(320, 'Benni Mack'),
            // Asked for: what Forge told counts
            new User(41, 'Benni Mack', fetchedOn: new \DateTimeImmutable('2026-11-20')),
            // Two people of the name with an avatar: which one it is, Forge tells
            new User(null, 'Stefan Bürk', 'stefan.buerk', 52213, ['stefan@example.org']),
            new User(null, 'Stefan Bürk', 'sbuerk', 52214, ['buerk@example.org']),
            new User(44702, 'Stefan Bürk'),
            new User(7, 'Nobody Else'),
        ]);

        self::assertSame(md5('benni@example.org'), $avatars['gerrit:7059']);
        self::assertSame(md5('benni@example.org'), $avatars['forge:320']);
        self::assertArrayNotHasKey('forge:41', $avatars);
        self::assertArrayNotHasKey('forge:44702', $avatars);
        self::assertArrayNotHasKey('forge:7', $avatars);
    }
}
