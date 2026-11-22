<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\People\User;
use App\Repository\StreamRepository;
use App\Repository\UserRepository;
use App\Search\GerritNameQuery;

/**
 * Gives an account on Gerrit that several users have to the one of its username. The lookup once took any account
 * Gerrit found for a username, by an email or a name, too, see User::lookedUpInGerrit(). The username of the account
 * comes from the stored activity, Gerrit is not asked. Without a username in the activity, no user keeps the account:
 * the lookup asks Gerrit again for the account of each username.
 */
final readonly class GerritAccountRepair
{
    public const string STEP = 'Give each account on Gerrit to its username';

    public function __construct(
        private UserRepository $users,
        private StreamRepository $stream,
    ) {
    }

    /**
     * @return int the users that lost an account
     */
    public function repair(): int
    {
        $users = $this->users->sharingGerrit();
        $gerritIds = array_values(array_unique(array_filter(
            array_map(static fn (User $user): ?int => $user->gerritId, $users),
        )));
        if ([] === $gerritIds) {
            return 0;
        }
        $usernames = GerritNames::usernamesOf(
            $this->stream->search(GerritNameQuery::request($gerritIds))->aggregation(GerritNameQuery::ACCOUNTS),
        );
        $repaired = self::repaired($users, $usernames);
        $this->users->write($repaired);

        return count($repaired);
    }

    /**
     * The users that keep an account on Gerrit of another username, without it.
     *
     * @param list<User>         $users
     * @param array<int, string> $usernames the usernames on Gerrit in lower case by the number
     *
     * @return list<User>
     */
    public static function repaired(array $users, array $usernames): array
    {
        $repaired = [];
        foreach ($users as $user) {
            $username = $usernames[(int) $user->gerritId] ?? null;
            if (null === $username || $username !== $user->username) {
                $repaired[] = $user->withoutGerrit();
            }
        }

        return $repaired;
    }
}
