<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\People;

use App\People\OwnAccounts;
use App\People\Person;
use App\People\User;
use App\People\UserDirectory;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Nobody follows oneself, though the index may not have joined the account on Forge with the one on Gerrit.
 */
final class OwnAccountsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function keys(): iterable
    {
        yield 'the username' => ['jdoe', true];
        yield 'the account on Forge of the email' => ['forge:7', true];
        yield 'an account on Gerrit of the same name' => ['gerrit:9', true];
        yield 'somebody else' => ['forge:8', false];
    }

    #[DataProvider('keys')]
    public function testItKnowsTheUser(string $key, bool $self): void
    {
        self::assertSame($self, $this->ownAccounts()->isSelf('jdoe', 'jane@example.org', 'Jane Doe', $key));
    }

    public function testItKnowsTheUserByTheNumbersOfTheName(): void
    {
        // The account on Gerrit has a name of its own, but the name of the person on Forge leads to it
        self::assertTrue($this->ownAccounts()->isSelf('jdoe', 'jane@example.org', 'J. Doe', 'forge:10'));
    }

    private function ownAccounts(): OwnAccounts
    {
        $people = [
            'forge:7' => new Person('Jane Doe', forgeId: 7),
            'gerrit:9' => new Person('Jane Doe', gerritId: 9),
            'forge:8' => new Person('John Roe', forgeId: 8),
            'forge:10' => new Person('Jane D.', forgeId: 10),
        ];
        $users = $this->createStub(UserDirectory::class);
        $users->method('personOf')->willReturnCallback(static fn (string $key): ?Person => $people[$key] ?? null);
        $users->method('numbersOf')->willReturnCallback(static fn (string $key, string $system): array => match (true) {
            'forge' === $system && null !== ($people[$key]->forgeId ?? null) => [(int) $people[$key]->forgeId],
            'gerrit' === $system && null !== ($people[$key]->gerritId ?? null) => [(int) $people[$key]->gerritId],
            'forge:10' === $key && 'gerrit' === $system => [9],
            default => [],
        });
        $accounts = $this->createStub(UserRepository::class);
        $accounts->method('accountsOf')->willReturn([
            new User(forgeId: 7, forgeName: 'Jane Doe'),
            new User(forgeId: null, forgeName: '', gerritId: 9, gerritName: 'J. Doe'),
        ]);

        return new OwnAccounts($users, $accounts);
    }
}
