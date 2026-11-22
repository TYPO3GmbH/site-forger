<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\People;

use App\Entity\PersonLink;
use App\People\PersonLinkCleanup;
use App\People\User;
use PHPUnit\Framework\TestCase;

final class PersonLinkCleanupTest extends TestCase
{
    public function testALinkTheSourcesJoinedIsNeedless(): void
    {
        $link = self::link('forge:128', 'just2b');

        // Forge told the username: the sync joined the account to the person
        self::assertTrue(PersonLinkCleanup::isJoined($link, new User(128, 'Georg Ringer', 'just2b', 7342)));
        // Forge was not asked yet: the link joins it
        self::assertFalse(PersonLinkCleanup::isJoined($link, new User(128, 'Georg Ringer')));
        self::assertFalse(PersonLinkCleanup::isJoined($link, null));
    }

    public function testTheSourcesContradictALinkOfAnAccountOnly(): void
    {
        self::assertSame(
            'someone',
            PersonLinkCleanup::contradicted(self::link('forge:5', 'just2b'), new User(5, 'Some One', 'someone')),
        );
        self::assertNull(PersonLinkCleanup::contradicted(self::link('forge:5', 'just2b'), new User(5, 'Some One')));
        // A renamed TYPO3 account joins two usernames on purpose
        self::assertNull(PersonLinkCleanup::contradicted(
            self::link('old_just2b', 'just2b'),
            new User(33777, 'Georg Ringer', 'old_just2b'),
        ));
    }

    private static function link(string $account, string $person): PersonLink
    {
        return new PersonLink($account, $person, 'admin', new \DateTimeImmutable('2026-11-22'));
    }
}
