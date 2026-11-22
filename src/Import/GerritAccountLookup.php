<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\CommandRun\CommandRunRecorder;
use App\Http\ApiClient;
use App\Http\ApiFailure;
use App\People\User;
use App\Repository\UserRepository;

/**
 * Finds the email of a person Forge shows no email of: in the account of the same username on Gerrit.
 *
 * Forge and Gerrit both log in with the TYPO3 account, so the Forge login is the Gerrit username. Gerrit shows the
 * account with its email without a login. A person Gerrit does not know stays without an email.
 */
final readonly class GerritAccountLookup
{
    /**
     * The step of "Update users" that asks Gerrit, as the command runs page shows it.
     */
    public const string STEP = 'Look up the users without an email in Gerrit';

    public function __construct(
        private ApiClient $gerrit,
        private UserRepository $users,
        private CommandRunRecorder $recorder,
    ) {
    }

    /**
     * Asks Gerrit for at most $limit users that are due, see due(), and writes them.
     *
     * @return array{asked: int, found: int}
     */
    public function lookUpDue(int $limit, \DateTimeImmutable $now): array
    {
        $due = self::due($this->users->all(), $now, $limit);
        $written = [];
        $found = 0;
        try {
            foreach ($due as $position => $user) {
                $lookedUp = $this->lookUp($user, $now);
                if (null === $lookedUp) {
                    break;
                }
                $written[] = $lookedUp;
                if (null !== $lookedUp->gerritId) {
                    ++$found;
                }
                $this->recorder->progress(self::STEP, $position + 1, count($due));
            }
        } finally {
            $this->users->write($written);
            $this->recorder->document(count($written));
        }

        return ['asked' => count($written), 'found' => $found];
    }

    /**
     * The user with the number and the email of its account on Gerrit, if Gerrit has one. Null when Gerrit fails:
     * then the user is asked for again later. With $always it asks for every user with a username, for an admin
     * who misses something of it, see App\Controller\PeopleController::refresh().
     */
    public function lookUp(User $user, \DateTimeImmutable $now, bool $always = false): ?User
    {
        if (null === $user->username || (!$always && !self::needsLookUp($user))) {
            return $user;
        }
        try {
            $account = User::fromGerrit($this->gerrit->object(
                '/accounts/' . rawurlencode((string) $user->username),
                [],
                'the account',
            ));
        } catch (ApiFailure $e) {
            if (!$e->isNotFound()) {
                $this->recorder->warning(sprintf('Gerrit failed for user %s: %s', $user->username, $e->getMessage()));

                return null;
            }
            $account = null;
        }

        return $user->lookedUpInGerrit($now, $account);
    }

    /**
     * Asks Gerrit for an account of Gerrit only by its number, for its username. With a username the account joins
     * the person of the username and its document "gerrit:<number>" goes. It does not join a person that has
     * another account on Gerrit: a Gerrit account belongs only to its username. Writes the account.
     *
     * @return array{user: User, known: bool}|null the account as written, and whether Gerrit knows it; null when
     *                                             Gerrit fails
     */
    public function lookUpNumber(User $user, \DateTimeImmutable $now): ?array
    {
        if (null === $user->gerritId) {
            return ['user' => $user, 'known' => false];
        }
        try {
            $account = User::fromGerrit($this->gerrit->object(
                '/accounts/' . $user->gerritId,
                [],
                'the account',
            ));
        } catch (ApiFailure $e) {
            if (!$e->isNotFound()) {
                $this->recorder->warning(sprintf('Gerrit failed for gerrit:%d: %s', $user->gerritId, $e->getMessage()));

                return null;
            }
            $account = null;
        }
        // Gerrit finds an account by more than its number, like an email or a name
        $own = null !== $account && $account->gerritId === $user->gerritId;
        $person = $own ? $this->personOf($account) : null;
        $written = null === $person ? $user->lookedUpInGerrit($now, null) : $person->lookedUpInGerrit($now, null);
        $this->users->write([$written], null === $person ? [] : [$user->id()]);

        return ['user' => $written, 'known' => $own];
    }

    /**
     * The person an account of Gerrit with a username joins: the document of the username with the account, or the
     * account alone. Null without a username, and for a person with another account on Gerrit.
     */
    private function personOf(User $account): ?User
    {
        if (null === $account->username) {
            return null;
        }
        $existing = $this->users->byIds([$account->username])[$account->username] ?? null;
        if (null === $existing) {
            return $account;
        }
        if (null !== $existing->gerritId && $existing->gerritId !== $account->gerritId) {
            return null;
        }

        return $existing->mergedWith($account);
    }

    /**
     * The users Forge told a username but no email of and Gerrit was not asked for, or asked for longer than
     * ForgeUserSync::REFRESH_AFTER_DAYS ago: a person may sign up to Gerrit later.
     *
     * @param array<int, User> $users
     *
     * @return list<User>
     */
    public static function due(array $users, \DateTimeImmutable $now, int $limit): array
    {
        $askAgainBefore = $now->modify(sprintf('-%d days', ForgeUserSync::REFRESH_AFTER_DAYS));
        $due = array_filter(
            $users,
            static fn (User $user): bool => null !== $user->fetchedOn
                && self::needsLookUp($user)
                && (null === $user->gerritLookedUpOn || $user->gerritLookedUpOn < $askAgainBefore),
        );
        usort($due, static fn (User $a, User $b): int => $a->gerritLookedUpOn <=> $b->gerritLookedUpOn);

        return array_slice($due, 0, max(0, $limit));
    }

    private static function needsLookUp(User $user): bool
    {
        return !$user->gone && [] === $user->emails && null !== $user->username && null === $user->gerritId;
    }
}
