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
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private const string SHA256 = '46663553a22f0c1daf03e2defcabdf6c1f8f1aada7aff2e0b5c503c63cd236e1';

    public function testFromForgeWithoutTheEmailHasNoAvatar(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 18:00:00', new \DateTimeZone('UTC'));
        $user = User::fromForge([
            'id' => 43800,
            'login' => 'NikitaH',
            'firstname' => 'Nikita',
            'lastname' => 'Hovratov',
        ], $now);

        self::assertSame('nikitah', $user->username);
        self::assertSame('nikitah', $user->id());
        self::assertSame('Nikita Hovratov', $user->name);
        self::assertSame([], $user->emails);
        // Gravatar finds the avatar by the email only: the pages show its placeholder
        self::assertNull($user->avatar());
        self::assertSame('https://www.gravatar.com/avatar/' . self::SHA256 . '?s=40&d=mp&r=g', User::avatarUrl(self::SHA256, 40));
        self::assertSame('2026/11/20 18:00:00', $user->toDocument()['fetched_on']);
    }

    public function testFromIssueHasNoUsernameAndTheAvatarOfTheEmail(): void
    {
        $user = User::fromIssue(['id' => 1, 'name' => 'Benni Mack', 'email' => ' Benni@Example.org ']);

        self::assertNotNull($user);
        self::assertNull($user->username);
        self::assertSame('forge:1', $user->id());
        self::assertSame(['benni@example.org'], $user->emails);
        self::assertSame(md5('benni@example.org'), $user->avatar());
        self::assertNull($user->fetchedOn);

        self::assertSame([], User::fromIssue(['id' => 2, 'name' => 'Somebody', 'email' => 'Unknown'])?->emails);
        self::assertNull(User::fromIssue(['name' => 'Without a number']));
    }

    public function testTheDocumentReadsBackAsTheUser(): void
    {
        $user = new User(38237, 'Benjamin Kott', 'benjaminkott', 12, ['benjamin@example.org'], new \DateTimeImmutable('2026-11-01 10:00:00', new \DateTimeZone('UTC')), false, new \DateTimeImmutable('2026-11-20 08:30:00', new \DateTimeZone('UTC')));

        self::assertEquals($user, User::fromDocument($user->toDocument()));
        // A single email, as Elasticsearch may hold it
        self::assertSame(['a@example.org'], User::fromDocument(['forge_id' => 1, 'email' => 'a@example.org'])->emails);
    }

    public function testFromGerritIsCompleteWithoutAForgeNumber(): void
    {
        $user = User::fromGerrit([
            '_account_id' => 52917,
            'name' => 'Ingo Hollmann',
            'username' => 'ingoholl',
            'email' => 'Ingo@Example.org',
        ]);

        self::assertNotNull($user);
        self::assertSame(['ingoholl', null, 52917, ['ingo@example.org'], md5('ingo@example.org')], [$user->id(), $user->forgeId, $user->gerritId, $user->emails, $user->avatar()]);
        self::assertEquals($user, User::fromDocument($user->toDocument()));
        self::assertSame('gerrit:7', User::fromGerrit(['_account_id' => 7, 'name' => 'Without username'])?->id());
        self::assertNull(User::fromGerrit(['name' => 'Without a number']));
    }

    public function testTheSamePersonOfForgeAndGerritIsOne(): void
    {
        $forge = new User(320, 'Benni Mack', 'benni', emails: ['benni@forge.example'], fetchedOn: new \DateTimeImmutable('2026-11-20'));
        $gerrit = new User(null, 'Benni', 'benni', 7059, ['benni@gerrit.example']);

        $merged = $forge->mergedWith($gerrit);

        self::assertSame(['benni', 320, 7059, 'Benni Mack', ['benni@forge.example', 'benni@gerrit.example'], md5('benni@forge.example')], [$merged->id(), $merged->forgeId, $merged->gerritId, $merged->name, $merged->emails, $merged->avatar()]);
        self::assertSame($forge->fetchedOn, $merged->fetchedOn);
        // The document merged into another keeps its own address first, which gives the avatar
        self::assertSame(md5('benni@gerrit.example'), $gerrit->mergedWith($forge)->avatar());
    }

    public function testForgeUpdatesWhatItTellsAndKeepsWhatOnlyTheIndexKnows(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 18:00:00');
        $taken = new User(1, 'Benni Mack', emails: ['benni@example.org'], gerritId: 7);

        $updated = $taken->updatedBy(User::fromForge(['id' => 1, 'login' => 'benni', 'firstname' => 'Benni', 'lastname' => 'Mack', 'mail' => 'mack@example.org'], $now));

        self::assertSame('benni', $updated->id());
        self::assertSame(['mack@example.org', 'benni@example.org'], $updated->emails);
        self::assertSame(md5('mack@example.org'), $updated->avatar());
        self::assertSame(7, $updated->gerritId);
        self::assertSame($now, $updated->fetchedOn);

        $gone = $taken->asGone($now, 403);
        self::assertTrue($gone->gone);
        self::assertSame($taken->emails, $gone->emails);
        self::assertSame('forge:1', $gone->id());
        // The answer of Forge is kept in the index
        self::assertSame(403, User::fromDocument($gone->toDocument())->goneStatus);
        self::assertTrue($gone->wasAsked());
        self::assertFalse((new User(1, 'Gone before', fetchedOn: $now, gone: true))->wasAsked());
    }

    public function testALoginTakesTheUsernameAndAddsTheEmail(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 18:00:00', new \DateTimeZone('UTC'));
        $taken = new User(1, 'Benni Mack', emails: ['benni@example.org'], gerritId: 7);

        $loggedIn = $taken->loggedIn('Benni', 'Benni@TYPO3.example', $now);

        self::assertSame(['benni', 1, 7, ['benni@example.org', 'benni@typo3.example']], [$loggedIn->id(), $loggedIn->forgeId, $loggedIn->gerritId, $loggedIn->emails]);
        // The avatar the pages showed stays
        self::assertSame(md5('benni@example.org'), $loggedIn->avatar());
        self::assertSame($now, $loggedIn->typo3LoginOn);
        self::assertSame('2026/11/20 18:00:00', $loggedIn->toDocument()['typo3_login_on']);
        // Without an address of its own the one of my.typo3.org gives the avatar
        self::assertSame(md5('benni@typo3.example'), (new User(1, 'Benni Mack'))->loggedIn('benni', 'benni@typo3.example', $now)->avatar());

        // Forge, the merge and the mark as gone keep the login
        self::assertSame($now, $loggedIn->updatedBy(User::fromForge(['id' => 1, 'login' => 'benni'], $now->modify('+1 hour')))->typo3LoginOn);
        self::assertSame($now, $loggedIn->asGone($now, 404)->typo3LoginOn);
        $later = $now->modify('+1 day');
        self::assertSame($later, $loggedIn->mergedWith($loggedIn->loggedIn('benni', 'benni@typo3.example', $later))->typo3LoginOn);
        self::assertSame($later, $loggedIn->loggedIn('benni', 'benni@typo3.example', $later)->mergedWith($loggedIn)->typo3LoginOn);
        self::assertSame($now, (new User(null, 'Benni', 'benni', 7))->mergedWith($loggedIn)->typo3LoginOn);
    }
}
