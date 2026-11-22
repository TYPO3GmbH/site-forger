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
use App\Import\GerritAccountLookup;
use App\People\User;
use App\Repository\UserRepository;
use App\Tests\Http\MockApi;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GerritAccountLookup::lookUpNumber(): an account of Gerrit only, asked for its username.
 */
final class GerritAccountLookupTest extends TestCase
{
    private const string NOW = '2026-11-22 18:00:00';

    /**
     * @var \ArrayObject<int, array{users: list<User>, replaced: list<string>}>
     */
    private \ArrayObject $writes;

    protected function setUp(): void
    {
        $this->writes = new \ArrayObject();
    }

    public function testAUsernameJoinsTheAccountToItsPerson(): void
    {
        $person = new User(320, 'Benni Mack', 'benni', fetchedOn: new \DateTimeImmutable(self::NOW));
        $lookup = $this->lookup([self::account(7059, 'benni')], ['benni' => $person]);

        $result = $lookup->lookUpNumber(self::gerritOnly(7059), new \DateTimeImmutable(self::NOW));

        self::assertNotNull($result);
        self::assertTrue($result['known']);
        self::assertSame(['benni', 320, 7059], [
            $result['user']->id(),
            $result['user']->forgeId,
            $result['user']->gerritId,
        ]);
        self::assertSame(['gerrit:7059'], $this->replaced());
    }

    public function testAnAccountWithoutAUsernameStaysOnItsOwn(): void
    {
        $lookup = $this->lookup([self::account(9033, null)]);

        $result = $lookup->lookUpNumber(self::gerritOnly(9033), new \DateTimeImmutable(self::NOW));

        self::assertNotNull($result);
        self::assertSame(['gerrit:9033', true], [$result['user']->id(), $result['known']]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $result['user']->gerritLookedUpOn);
        self::assertSame([], $this->replaced());
    }

    public function testAnotherAccountOfTheAnswerIsNotTheOneAskedFor(): void
    {
        $lookup = $this->lookup([self::account(1, 'somebody')]);

        $result = $lookup->lookUpNumber(self::gerritOnly(9033), new \DateTimeImmutable(self::NOW));

        self::assertNotNull($result);
        self::assertSame(['gerrit:9033', false], [$result['user']->id(), $result['known']]);
    }

    public function testAPersonWithAnotherAccountOnGerritTakesNoSecondOne(): void
    {
        $person = new User(320, 'Benni Mack', 'benni', 1);
        $lookup = $this->lookup([self::account(7059, 'benni')], ['benni' => $person]);

        $result = $lookup->lookUpNumber(self::gerritOnly(7059), new \DateTimeImmutable(self::NOW));

        self::assertNotNull($result);
        self::assertSame('gerrit:7059', $result['user']->id());
        self::assertSame([], $this->replaced());
    }

    public function testAnAccountGerritDoesNotKnowIsMarkedAsAsked(): void
    {
        $result = $this->lookup([new Response(404)])
            ->lookUpNumber(self::gerritOnly(52074), new \DateTimeImmutable(self::NOW));

        self::assertNotNull($result);
        self::assertFalse($result['known']);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $result['user']->gerritLookedUpOn);
    }

    /**
     * @return list<string> the documents the first write replaced
     */
    private function replaced(): array
    {
        foreach ($this->writes as $write) {
            return $write['replaced'];
        }
        self::fail('The lookup wrote nothing.');
    }

    /**
     * @param list<User>   $users
     * @param list<string> $replaced
     */
    private function recordWrite(array $users, array $replaced = []): void
    {
        $this->writes->append(['users' => $users, 'replaced' => $replaced]);
    }

    private static function gerritOnly(int $number): User
    {
        return new User(null, '', gerritId: $number, gerritName: 'anonymized-' . $number);
    }

    private static function account(int $number, ?string $username): Response
    {
        $account = array_filter(['_account_id' => $number, 'name' => 'Somebody', 'username' => $username]);

        return new Response(200, [], ")]}'\n" . json_encode($account));
    }

    /**
     * @param list<Response>      $responses of Gerrit
     * @param array<string, User> $documents of the index, by id
     */
    private function lookup(array $responses, array $documents = []): GerritAccountLookup
    {
        $recorder = new CommandRunRecorder($this->createStub(Connection::class), new NullLogger());
        $users = $this->createStub(UserRepository::class);
        $users->method('byIds')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key($documents, array_flip($ids)),
        );
        $users->method('write')->willReturnCallback($this->recordWrite(...));

        return new GerritAccountLookup((new MockApi($responses, $recorder, 'Gerrit'))->client, $users, $recorder);
    }
}
