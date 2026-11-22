<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Repository\UserRepository;

/**
 * Tells whether a person is the user logged in: nobody follows oneself, see App\Controller\FollowController. Also
 * finds the accounts of the user on Gerrit.
 */
final readonly class OwnAccounts
{
    public function __construct(
        private UserDirectory $users,
        private UserRepository $accounts,
    ) {
    }

    /**
     * Whether the key names the user. The index may not have joined the account on Forge with the one on Gerrit,
     * so it compares as My Work finds the work of the user: the username, the numbers of the accounts of the
     * username or the email, and the name, see MyWorkController.
     */
    public function isSelf(string $username, string $email, string $name, string $key): bool
    {
        if (mb_strtolower(trim($username)) === $key) {
            return true;
        }
        $personName = mb_strtolower(trim((string) $this->users->personOf($key)?->name));
        if ('' !== $personName && mb_strtolower(trim($name)) === $personName) {
            return true;
        }
        $accounts = $this->accounts->accountsOf($username, $email);
        $forgeIds = array_filter(array_map(static fn (User $account): ?int => $account->forgeId, $accounts));

        return [] !== array_intersect($this->users->numbersOf($key, 'forge'), $forgeIds)
            || [] !== array_intersect($this->users->numbersOf($key, 'gerrit'), self::gerritIdsIn($accounts));
    }

    /**
     * The accounts of the user on Gerrit: the filter "Waiting for" finds the changes the user did not vote on.
     *
     * @return list<int>
     */
    public function gerritIds(string $username, string $email): array
    {
        return self::gerritIdsIn($this->accounts->accountsOf($username, $email));
    }

    /**
     * @param list<User> $accounts
     *
     * @return list<int>
     */
    private static function gerritIdsIn(array $accounts): array
    {
        return array_values(array_filter(array_map(static fn (User $account): ?int => $account->gerritId, $accounts)));
    }
}
