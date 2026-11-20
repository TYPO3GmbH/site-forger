<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Import;

use App\CommandRun\CommandRunRecorder;
use App\Elastic\Indices;
use App\Import\ForgeUserSync;
use App\People\User;
use App\Repository\UserRepository;
use App\Tests\Http\MockApi;
use Doctrine\DBAL\Connection;
use Elastica\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ForgeUserSyncTest extends TestCase
{
    public function testOnlyThePeopleTheIndexLacksAreTakenOver(): void
    {
        $people = [1 => new User(1, 'Benni Mack'), 2 => new User(2, 'Benjamin Kott')];

        self::assertSame([$people[2]], ForgeUserSync::missing($people, [1 => new User(1, 'Benni Mack', 'benni')]));
    }

    public function testAUsernameForgeToldJoinsTheDocumentOfGerrit(): void
    {
        $fromForge = new User(320, 'Benni Mack', 'benni', emails: ['mack@example.org']);
        $ofGerrit = new User(null, 'Benni', 'benni', 7059, ['benni@example.org']);
        $alone = new User(41, 'Somebody', 'somebody');

        $merged = ForgeUserSync::merged([$fromForge, $alone], ['benni' => $ofGerrit]);

        self::assertSame([320, 7059, ['mack@example.org', 'benni@example.org'], md5('mack@example.org')], [$merged[0]->forgeId, $merged[0]->gerritId, $merged[0]->emails, $merged[0]->avatar()]);
        self::assertSame($alone, $merged[1]);
        // A user asked for again keeps its document as it is
        self::assertSame($fromForge, ForgeUserSync::merged([$fromForge], ['benni' => $fromForge])[0]);
    }

    public function testALoginWhileForgeIsAskedIsKept(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 18:00:00');
        // Read before the login, without the username: Forge tells it
        $taken = new User(320, 'Benni Mack');
        $loggedIn = $taken->loggedIn('benni', 'benni@typo3.example', $now);
        $fromForge = $taken->updatedBy(User::fromForge(['id' => 320, 'login' => 'benni', 'firstname' => 'Benni', 'lastname' => 'Mack'], $now));

        $merged = ForgeUserSync::merged([$fromForge], ['benni' => $loggedIn])[0];

        self::assertSame(['benni', ['benni@typo3.example']], [$merged->id(), $merged->emails]);
        self::assertEquals($now, $merged->typo3LoginOn);
        self::assertSame($now, $merged->fetchedOn);
    }

    public function testNeverAskedForComeFirstThoseWithoutEmailBeforeThem(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 18:00:00');
        $users = [
            1 => new User(1, 'Asked for lately', 'one', fetchedOn: $now->modify('-1 day')),
            2 => new User(2, 'Asked for long ago', 'two', fetchedOn: $now->modify('-40 days')),
            3 => new User(3, 'Taken over with email', emails: ['three@example.org']),
            4 => new User(4, 'Taken over without email'),
            5 => new User(5, 'Asked for longer ago', 'five', fetchedOn: $now->modify('-60 days')),
            6 => new User(6, 'Newer, without email'),
        ];

        $due = ForgeUserSync::due($users, $now, 10);

        self::assertSame([6, 4, 3, 5, 2], array_map(static fn (User $user): ?int => $user->forgeId, $due));
        self::assertCount(2, ForgeUserSync::due($users, $now, 2));
        self::assertSame([], ForgeUserSync::due($users, $now, 0));
    }

    public function testUsersMarkedAsGoneBeforeTheStatusWasStoredAreAskedForFirst(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 18:00:00');
        $users = [
            1 => new User(1, 'Never asked for'),
            2 => new User(2, 'Gone, without status', fetchedOn: $now->modify('-1 day'), gone: true),
            3 => new User(3, 'Gone with 404', fetchedOn: $now->modify('-1 day'), gone: true, goneStatus: 404),
        ];

        $due = ForgeUserSync::due($users, $now, 10);

        self::assertSame([2, 1], array_map(static fn (User $user): ?int => $user->forgeId, $due));
    }

    public function testATokenThatMayNotSeeUsersMarksNobodyAsGone(): void
    {
        $written = new \ArrayObject();
        $sync = $this->sync(array_fill(0, 6, new Response(403)), $written);

        $result = $sync->update([1, 2, 3, 4, 5, 6], new \DateTimeImmutable());

        self::assertSame(0, $result['gone']);
        self::assertStringContainsString('the token may not see users', (string) $result['stopped']);
        self::assertCount(0, $written);
    }

    public function testRefusedUsersAreGoneWithTheirStatusOnceForgeAnswersAnother(): void
    {
        $written = new \ArrayObject();
        $sync = $this->sync([
            new Response(403),
            new Response(
                200,
                [],
                (string) json_encode(['user' => ['id' => 2, 'firstname' => 'Two', 'login' => 'two']]),
            ),
            new Response(404),
        ], $written);

        $result = $sync->update([1, 2, 3], new \DateTimeImmutable());

        self::assertSame(['updated' => 1, 'gone' => 2, 'stopped' => null], $result);
        $status = [];
        foreach ($written as $user) {
            $status[(int) $user->forgeId] = $user->goneStatus;
        }
        self::assertSame([2 => null, 3 => 404, 1 => 403], $status);
    }

    /**
     * @param list<Response>          $responses of Forge
     * @param \ArrayObject<int, User> $written   gets the users written to the index
     */
    private function sync(array $responses, \ArrayObject $written): ForgeUserSync
    {
        $recorder = new CommandRunRecorder($this->createStub(Connection::class), new NullLogger());
        $users = $this->createStub(UserRepository::class);
        $users->method('all')->willReturn([]);
        $users->method('write')->willReturnCallback(static function (array $users) use ($written): void {
            $written->exchangeArray($users);
        });

        return new ForgeUserSync(
            new Indices(new Client()),
            (new MockApi($responses, $recorder))->client,
            $users,
            $recorder,
        );
    }
}
