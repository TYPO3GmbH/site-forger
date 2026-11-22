<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\Elastic\Bucket;
use App\People\User;
use App\Repository\StreamRepository;
use App\Repository\UserRepository;
use App\Search\GerritNameQuery;

/**
 * Fills the name of Gerrit of the users from the stored activity, without asking Gerrit: the pages show it, see
 * User::displayName(). A user whose account has no activity gets an empty name, so it is not looked for again. An
 * import of Gerrit sets the name of the accounts it sees, see User::fromGerrit().
 */
final readonly class GerritNames
{
    public const string STEP = 'Take the names of Gerrit over from the activity';

    /**
     * The users it looks at per run: one request to the stream each.
     */
    public const int PER_RUN = 1000;

    public function __construct(
        private UserRepository $users,
        private StreamRepository $stream,
    ) {
    }

    /**
     * @return int the users written
     */
    public function fill(): int
    {
        $users = $this->users->withoutGerritName(self::PER_RUN);
        $gerritIds = array_values(array_filter(array_map(static fn (User $user): ?int => $user->gerritId, $users)));
        if ([] === $gerritIds) {
            return 0;
        }
        $names = self::namesOf(
            $this->stream->search(GerritNameQuery::request($gerritIds))->aggregation(GerritNameQuery::ACCOUNTS),
        );
        $written = array_map(
            static fn (User $user): User => $user->withGerritName($names[(int) $user->gerritId] ?? ''),
            $users,
        );
        $this->users->write($written);

        return count($written);
    }

    /**
     * @param array<string, mixed>|null $aggregation the aggregation ACCOUNTS of GerritNameQuery::request()
     *
     * @return array<int, string> the usernames in lower case by the number on Gerrit
     */
    public static function usernamesOf(?array $aggregation): array
    {
        $usernames = [];
        foreach (Bucket::listOf($aggregation) as $bucket) {
            $source = $bucket->topHit(GerritNameQuery::LATEST);
            $username = mb_strtolower(trim((string) ($source['author']['username'] ?? '')));
            if ('' !== $username) {
                $usernames[(int) $bucket->key] = $username;
            }
        }

        return $usernames;
    }

    /**
     * @param array<string, mixed>|null $aggregation the aggregation ACCOUNTS of GerritNameQuery::request()
     *
     * @return array<int, string> the names by the number on Gerrit
     */
    public static function namesOf(?array $aggregation): array
    {
        $names = [];
        foreach (Bucket::listOf($aggregation) as $bucket) {
            $source = $bucket->topHit(GerritNameQuery::LATEST);
            $name = trim((string) ($source['author']['name'] ?? ''));
            if ('' !== $name) {
                $names[(int) $bucket->key] = $name;
            }
        }

        return $names;
    }
}
