<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Elastic\Bucket;
use App\Elastic\Indices;
use App\People\User;
use App\People\UserDirectory;
use App\People\UserStatistics;
use Elastica\Document;
use Elastica\Index;
use Elastica\Query;
use Elastica\Result;
use Psr\Log\LoggerInterface;

/**
 * The people of Forge and Gerrit, one per TYPO3 account, stored in the index "user".
 *
 * Forge tells more than the number and the name of a user only user by user, one request each, see ForgeUserSync.
 * Gerrit tells all of it with each change. Nothing here writes the issue index: a user that changes changes no issue.
 */
class UserRepository
{
    /**
     * The numbers the index has, by "forge:<number>" and "gerrit:<number>". The importers load them once per
     * process, see known().
     *
     * @var array<string, true>|null
     */
    private ?array $known = null;

    public function __construct(
        private readonly Indices $indices,
        private readonly UserDirectory $directory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The person of a TYPO3 account, null if the index does not know the username.
     */
    public function byUsername(string $username): ?User
    {
        $username = mb_strtolower(trim($username));
        if ('' === $username || str_contains($username, ':')) {
            return null;
        }

        return $this->byIds([$username])[$username] ?? null;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, User> by the id of their document
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return [];
        }
        $users = [];
        $results = $this->indices->users()->search([
            'query' => ['ids' => ['values' => $ids]],
            'size' => count($ids),
        ])->getResults();
        foreach ($results as $result) {
            $users[(string) $result->getId()] = User::fromDocument($result->getSource());
        }

        return $users;
    }

    /**
     * The people whose username starts with the term, for the search of people. Without the people gone.
     *
     * @return list<User>
     */
    public function byUsernamePrefix(string $term, int $size): array
    {
        $term = mb_strtolower(trim($term));
        if ('' === $term) {
            return [];
        }
        $results = $this->indices->users()->search([
            'query' => ['bool' => [
                'filter' => [['prefix' => ['account.username' => $term]]],
                'must_not' => [['term' => ['forge.gone' => true]]],
            ]],
            'size' => $size,
        ])->getResults();

        return array_values(array_map(
            static fn (Result $result): User => User::fromDocument($result->getSource()),
            $results,
        ));
    }

    /**
     * The document of a key: "forge:<number>" and "gerrit:<number>" by the account, else by the id. Null when the
     * index has none.
     */
    public function byKey(string $key): ?User
    {
        $key = mb_strtolower(trim($key));
        if (1 !== preg_match('/^(forge|gerrit):(\d{1,9})$/', $key, $matches)) {
            return '' === $key ? null : ($this->byIds([$key])[$key] ?? null);
        }
        $hit = $this->indices->users()->search([
            'size' => 1,
            'query' => ['term' => [$matches[1] . '.id' => (int) $matches[2]]],
        ])->getResults()[0] ?? null;

        return null === $hit ? null : User::fromDocument($hit->getSource());
    }

    /**
     * The people whose TYPO3 username starts with the prefix, like "old_", also those Forge does not know.
     *
     * @return list<User>
     */
    public function withUsernamePrefix(string $prefix, int $size): array
    {
        $results = $this->indices->users()->search([
            'query' => ['prefix' => ['account.username' => mb_strtolower($prefix)]],
            'size' => $size,
        ])->getResults();

        return array_values(array_map(
            static fn (Result $result): User => User::fromDocument($result->getSource()),
            $results,
        ));
    }

    /**
     * The people whose name another document has, too, by the name: one person in several documents, or several
     * people of one name. A name has at most five here.
     *
     * @return array<string, list<User>>
     */
    public function sharingNames(int $size): array
    {
        $result = $this->indices->users()->search([
            'size' => 0,
            'aggs' => ['names' => [
                'terms' => ['field' => 'name', 'min_doc_count' => 2, 'size' => $size],
                'aggs' => ['documents' => ['top_hits' => ['size' => 5]]],
            ]],
        ]);
        $names = [];
        foreach ((array) ($result->getAggregations()['names']['buckets'] ?? []) as $bucket) {
            $hits = (array) ($bucket['documents']['hits']['hits'] ?? []);
            $names[(string) $bucket['key']] = array_values(array_map(
                static fn (array $hit): User => User::fromDocument((array) $hit['_source']),
                $hits,
            ));
        }

        return $names;
    }

    /**
     * The people with the email among their addresses. Several accounts can share an address.
     *
     * @return list<User>
     */
    public function byEmail(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return [];
        }
        $results = $this->indices->users()->search([
            'query' => ['term' => ['email' => $email]],
            'size' => 10,
        ])->getResults();

        return array_values(array_map(
            static fn (Result $result): User => User::fromDocument($result->getSource()),
            $results,
        ));
    }

    /**
     * The people of the person logged in: the account by its username, else the people with its email.
     * Empty if the index knows neither or cannot be read. Then the work of the person is found by the name.
     *
     * @return list<User>
     */
    public function accountsOf(string $username, string $email): array
    {
        try {
            $user = $this->byUsername($username);

            return null === $user ? $this->byEmail($email) : [$user];
        } catch (\Exception $e) {
            $this->logger->warning('The users could not be read: ' . $e->getMessage(), ['exception' => $e]);

            return [];
        }
    }

    /**
     * Notes a login with the TYPO3 account, see Typo3LoginSubscriber.
     * The person gets the email of my.typo3.org, the time, the name of the account, which the pages show from then
     * on, and the username as the id of its document. Writes nothing for a person the index does not know: the
     * index holds the people of Forge and Gerrit only.
     *
     * @return User|null the person written
     */
    public function recordLogin(string $username, string $email, \DateTimeImmutable $now, string $name = ''): ?User
    {
        $username = mb_strtolower(trim($username));
        $email = mb_strtolower(trim($email));
        $user = self::loginTarget($username, $this->byUsername($username), $this->byEmail($email));
        if (null === $user) {
            return null;
        }
        $loggedIn = $user->loggedIn($username, $email, $now, $name);
        $this->write([$loggedIn], [$user->id()]);

        return $loggedIn;
    }

    /**
     * The person a login with the TYPO3 account belongs to: the one with the username. Else the one person with
     * the email that has no username yet. None if the email belongs to another account, or to several people
     * without a username: Forge tells which one it is, see ForgeUserSync.
     *
     * @param list<User> $byEmail
     */
    public static function loginTarget(string $username, ?User $byUsername, array $byEmail): ?User
    {
        if (null !== $byUsername) {
            return $byUsername;
        }
        if ('' === $username || str_contains($username, ':')) {
            return null;
        }
        $candidates = array_values(array_filter($byEmail, static fn (User $user): bool => null === $user->username));

        return 1 === count($candidates) ? $candidates[0] : null;
    }

    /**
     * Writes the Forge users the index does not have yet, with their number and name only. "Update users" fills
     * them, see ForgeUserSync. The importer calls this for every issue, so the known numbers stay in memory.
     *
     * @param list<User> $users
     *
     * @return int how many it wrote
     */
    public function ensure(array $users): int
    {
        $known = $this->known();
        $missing = [];
        foreach ($users as $user) {
            if (null !== $user->forgeId && !isset($known['forge:' . $user->forgeId])) {
                $missing[$user->forgeId] = new User($user->forgeId, $user->forgeName);
            }
        }
        $this->write(array_values($missing));

        return count($missing);
    }

    /**
     * Writes the Gerrit accounts the index does not have yet, complete as Gerrit tells them with a change.
     * A person the index knows by the username, from Forge, gets the number on Gerrit added.
     *
     * @param list<User> $users of Gerrit, see User::fromGerrit()
     *
     * @return int how many it wrote
     */
    public function ensureGerrit(array $users): int
    {
        $missing = [];
        foreach ($users as $user) {
            // known() reads all users of the index, so it runs only for a user of Gerrit
            if (null !== $user->gerritId && !isset($this->known()['gerrit:' . $user->gerritId])) {
                $missing[$user->gerritId] = $user;
            }
        }
        if ([] === $missing) {
            return 0;
        }
        $usernames = array_filter(array_map(static fn (User $user): ?string => $user->username, $missing));
        $existing = $this->byIds(array_values($usernames));
        $written = array_map(
            static fn (User $user): User => ($existing[$user->id()] ?? null)?->mergedWith($user) ?? $user,
            array_values($missing),
        );
        $this->write($written);

        return count($written);
    }

    /**
     * @return array<int, User> the users with a Forge number, by that number
     */
    public function all(): array
    {
        $users = [];
        foreach (self::documents($this->indices->users(), ['exists' => ['field' => 'forge.id']]) as $user) {
            $users[(int) $user->forgeId] = $user;
        }

        return $users;
    }

    /**
     * The users with an account on Gerrit whose name of Gerrit was not looked for, see App\Import\GerritNames.
     *
     * @return list<User>
     */
    public function withoutGerritName(int $size): array
    {
        $result = $this->indices->users()->search([
            'size' => $size,
            'query' => ['bool' => [
                'filter' => [['exists' => ['field' => 'gerrit.id']]],
                'must_not' => [['exists' => ['field' => 'gerrit.name']]],
            ]],
        ]);

        return array_values(array_map(
            static fn (Result $hit): User => User::fromDocument($hit->getSource()),
            $result->getResults(),
        ));
    }

    /**
     * The users that have an account on Gerrit another user has, too, see App\Import\GerritAccountRepair.
     *
     * @return list<User>
     */
    public function sharingGerrit(): array
    {
        $result = $this->indices->users()->search([
            'size' => 0,
            'aggs' => ['shared' => ['terms' => ['field' => 'gerrit.id', 'min_doc_count' => 2, 'size' => 1000]]],
        ]);
        $shared = array_map(
            static fn (Bucket $bucket): int => (int) $bucket->key,
            Bucket::listOf($result->getAggregations()['shared'] ?? null)->getValues(),
        );
        if ([] === $shared) {
            return [];
        }

        return iterator_to_array(
            self::documents($this->indices->users(), ['terms' => ['gerrit.id' => $shared]]),
            false,
        );
    }

    /**
     * The users whose document has an older form than User::SCHEMA, see App\Import\UserForms.
     *
     * @return list<User>
     */
    public function inOlderForm(int $size): array
    {
        $result = $this->indices->users()->search([
            'size' => $size,
            'query' => ['bool' => ['must_not' => [['term' => ['schema' => User::SCHEMA]]]]],
        ]);

        return array_values(array_map(
            static fn (Result $hit): User => User::fromDocument($hit->getSource()),
            $result->getResults(),
        ));
    }

    /**
     * The user Forge answered for last, null if Forge answered for none. The system page asks Forge for it again,
     * see ForgeLogin.
     */
    public function lastFetched(): ?User
    {
        $result = $this->indices->users()->search([
            'size' => 1,
            'query' => ['bool' => [
                'filter' => [['exists' => ['field' => 'forge.id']], ['exists' => ['field' => 'forge.fetched_on']]],
                'must_not' => [['term' => ['forge.gone' => true]]],
            ]],
            'sort' => [['forge.fetched_on' => 'desc']],
        ]);
        $hit = $result->getResults()[0] ?? null;

        return null === $hit ? null : User::fromDocument($hit->getSource());
    }

    /**
     * All users Forge was asked for that have no email: first those unknown to Forge by its answer, then those Forge
     * does not show the email of, each by name.
     *
     * @return list<User>
     */
    public function unmapped(): array
    {
        $result = $this->indices->users()->search([
            // The most Elasticsearch returns at once, many more than the index has people without an email
            'size' => 10000,
            'query' => ['bool' => [
                'filter' => [['exists' => ['field' => 'forge.id']], ['exists' => ['field' => 'forge.fetched_on']]],
                'must_not' => [['exists' => ['field' => 'email']]],
            ]],
            'sort' => [
                ['forge.gone' => ['order' => 'desc', 'missing' => '_last']],
                ['forge.gone_status' => ['order' => 'asc', 'missing' => '_first']],
                ['forge.name' => ['order' => 'asc', 'missing' => '_last']],
            ],
        ]);

        return array_values(array_map(
            static fn (Result $hit): User => User::fromDocument($hit->getSource()),
            $result->getResults(),
        ));
    }

    /**
     * How far Forger knows the TYPO3 login and the email of the Forge users, for the system page, see UserHealth.
     * The people only Gerrit knows are complete with their change, so they do not count.
     */
    public function statistics(): UserStatistics
    {
        $notAsked = ['bool' => ['must_not' => [['exists' => ['field' => 'forge.fetched_on']]]]];
        $gone = ['term' => ['forge.gone' => true]];
        $result = $this->indices->users()->search([
            'size' => 0,
            'track_total_hits' => true,
            'query' => ['exists' => ['field' => 'forge.id']],
            'aggs' => [
                'withLogin' => ['filter' => ['exists' => ['field' => 'account.username']]],
                'withEmail' => ['filter' => ['exists' => ['field' => 'email']]],
                'notAskedYet' => ['filter' => $notAsked],
                'unknownToForge' => ['filter' => ['bool' => [
                    'filter' => [$gone],
                    'must_not' => [['exists' => ['field' => 'account.username']]],
                ]], 'aggs' => [
                    'status' => ['terms' => ['field' => 'forge.gone_status', 'missing' => 0, 'size' => 10]],
                ]],
                'withoutEmail' => ['filter' => ['bool' => [
                    'filter' => [['exists' => ['field' => 'forge.fetched_on']]],
                    'must_not' => [$gone, ['exists' => ['field' => 'email']]],
                ]]],
                'gone' => ['filter' => $gone],
                'lastFetched' => ['max' => ['field' => 'forge.fetched_on']],
            ],
        ]);
        $aggregations = $result->getAggregations();
        $goneByStatus = [];
        foreach (Bucket::listOf($aggregations['unknownToForge']['status'] ?? null) as $status) {
            $goneByStatus[(int) $status->key] = $status->count;
        }
        $last = $aggregations['lastFetched']['value'] ?? null;

        return new UserStatistics(
            total: $result->getTotalHits(),
            withLogin: (int) ($aggregations['withLogin']['doc_count'] ?? 0),
            withEmail: (int) ($aggregations['withEmail']['doc_count'] ?? 0),
            notAskedYet: (int) ($aggregations['notAskedYet']['doc_count'] ?? 0),
            unknownToForge: (int) ($aggregations['unknownToForge']['doc_count'] ?? 0),
            withoutEmail: (int) ($aggregations['withoutEmail']['doc_count'] ?? 0),
            gone: (int) ($aggregations['gone']['doc_count'] ?? 0),
            goneByStatus: $goneByStatus,
            lastFetched: null === $last ? null : new \DateTimeImmutable('@' . intdiv((int) $last, 1000)),
        );
    }

    /**
     * @param list<string> $ids
     */
    public function remove(array $ids): void
    {
        $this->write([], $ids);
    }

    /**
     * Writes users, and removes the documents they had under another id before. A user taken over from the issues
     * is "forge:<number>" until Forge tells the username.
     *
     * @param list<User>   $users
     * @param list<string> $replaced ids of documents the users replace
     */
    public function write(array $users, array $replaced = []): void
    {
        $ids = array_map(static fn (User $user): string => $user->id(), $users);
        $replaced = array_values(array_diff($replaced, $ids));
        if ([] === $users && [] === $replaced) {
            return;
        }
        $index = $this->indices->users();
        if ([] !== $users) {
            $index->addDocuments(array_map(
                static fn (User $user): Document => new Document($user->id(), $user->toDocument()),
                $users,
            ));
        }
        if ([] !== $replaced) {
            $index->deleteByQuery(new Query(['query' => ['ids' => ['values' => $replaced]]]));
        }
        $index->refresh();
        $this->directory->forget();
        if (null !== $this->known) {
            foreach ($users as $user) {
                $this->known += array_fill_keys($user->keys(), true);
            }
        }
    }

    /**
     * All users that match the query, read in batches.
     *
     * @param array<string, mixed> $query
     *
     * @return \Generator<User>
     */
    public static function documents(Index $index, array $query): \Generator
    {
        $search = $index->createSearch(new Query(['size' => 1000, 'query' => $query]));
        foreach ($search->scroll('1m') as $resultSet) {
            foreach ($resultSet->getResults() as $result) {
                yield User::fromDocument($result->getSource());
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function known(): array
    {
        if (null === $this->known) {
            $this->known = [];
            foreach (self::documents($this->indices->users(), ['match_all' => new \stdClass()]) as $user) {
                $this->known += array_fill_keys($user->keys(), true);
            }
        }

        return $this->known;
    }
}
