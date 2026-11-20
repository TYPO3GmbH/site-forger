<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Elastic\Indices;
use App\Search\PeopleFacet;
use Elastica\Document;
use Elastica\Query;
use Elastica\Result;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The index "user": the people of Forge and Gerrit, one document per TYPO3 account, see App\People\User.
 *
 * The issues keep only the number and the name of their author and assignee, as Forge lists them: Forge tells the
 * rest only user by user, one request each, see App\Import\ForgeUserSync. The changes keep the number of their
 * owner on Gerrit, which tells all of it with the change. The pages take the avatars from here, of Forge and of
 * Gerrit alike. Nothing here writes the issue index: a user that changes changes no issue.
 */
class Users
{
    /**
     * The avatars by "forge:<number>" and "gerrit:<number>", cached for the pages: a page shows dozens of them.
     */
    private const string AVATARS_CACHE_KEY = 'user_avatars';
    private const int AVATARS_CACHE_LIFETIME = 600;

    /**
     * The keys of the people, see keyOf() and personOf(), cached for the pages as the avatars.
     */
    private const string PEOPLE_CACHE_KEY = 'user_people';

    /** @var array<string, string>|null */
    private ?array $avatars = null;

    /**
     * @var array{keys: array<string, string>, people: array<string, array{name: string, username: string|null, forge: int|null, gerrit: int|null}>}|null
     */
    private ?array $people = null;

    /**
     * The numbers of the people by "forge" or "gerrit" and their name, see numbersOf().
     *
     * @var array<string, array<string, list<int>>>
     */
    private array $byName = [];

    /**
     * The numbers the index has, by "forge:<number>" and "gerrit:<number>", loaded once per process for the
     * importers, see known().
     *
     * @var array<string, true>|null
     */
    private ?array $known = null;

    public function __construct(
        private readonly Indices $indices,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The address of the avatar of a user as the documents have the person, see templates/_macros/user.html.twig:
     * by the number on Forge ("id") or on Gerrit ("gerrit_id"), from the index; else by the "email", for the changes
     * imported before the number of their owner. Null if none is known.
     *
     * @param array<string, mixed>|null $user
     */
    public function avatarOfUser(?array $user, int $size): ?string
    {
        if (isset($user['id'])) {
            return $this->avatarOf((int) $user['id'], $size);
        }
        if (isset($user['gerrit_id']) && null !== ($url = $this->gerritAvatarOf((int) $user['gerrit_id'], $size))) {
            return $url;
        }
        $email = mb_strtolower(trim((string) ($user['email'] ?? '')));

        return str_contains($email, '@') ? User::avatarUrl(User::avatarHash($email), $size) : null;
    }

    /**
     * The address of the avatar of a Forge user, null if the index knows none.
     */
    public function avatarOf(?int $forgeId, int $size): ?string
    {
        return null === $forgeId ? null : $this->avatarBy('forge:' . $forgeId, $size);
    }

    /**
     * The address of the avatar of a Gerrit account, null if the index knows none.
     */
    public function gerritAvatarOf(?int $gerritId, int $size): ?string
    {
        return null === $gerritId ? null : $this->avatarBy('gerrit:' . $gerritId, $size);
    }

    /**
     * The key of a person in the filters of people, "filters[author][<key>]", see PeopleFacet: the id of its
     * document, the username; "forge:<number>" or "gerrit:<number>" while the index knows none.
     *
     * @param string $system "forge" or "gerrit"
     */
    public function keyOf(string $system, int $number): string
    {
        $key = $system . ':' . $number;

        return $this->people()['keys'][$key] ?? $key;
    }

    /**
     * The key of a person as the documents have it, see templates/_macros/user.html.twig: by the number on Forge
     * ("id") or on Gerrit ("gerrit_id"); else the name with "_" for the spaces, as the filters had them before.
     *
     * @param array<string, mixed>|null $user
     */
    public function keyOfUser(?array $user): string
    {
        if (isset($user['id'])) {
            return $this->keyOf('forge', (int) $user['id']);
        }
        if (isset($user['gerrit_id'])) {
            return $this->keyOf('gerrit', (int) $user['gerrit_id']);
        }

        return str_replace(' ', '_', trim((string) ($user['name'] ?? '')));
    }

    /**
     * The person of a key of the filters of people, see keyOf(): its name, username and numbers on Forge and Gerrit.
     * A key "forge:<number>" the index has no document of is the number alone. Null for a key that is no person's:
     * a name, as the links had them before.
     *
     * @return array{name: string, username: string|null, forge: int|null, gerrit: int|null}|null
     */
    public function personOf(string $key): ?array
    {
        $people = $this->people();
        if (1 === preg_match('/^(forge|gerrit):(\d+)$/', $key, $matches)) {
            $id = $people['keys'][$key] ?? null;

            return null === $id ? [
                'name' => '',
                'username' => null,
                'forge' => 'forge' === $matches[1] ? (int) $matches[2] : null,
                'gerrit' => 'gerrit' === $matches[1] ? (int) $matches[2] : null,
            ] : $people['people'][$id];
        }

        return $people['people'][mb_strtolower($key)] ?? null;
    }

    /**
     * The numbers on Forge or Gerrit of a key of the filters of people: the number of the person; without one the
     * numbers of the people of the same name, as the index has them until ForgeUserSync joins the person of Gerrit
     * and the one of the issues. A key that is no person's is a name, as the links had them before. Empty if the
     * index knows nobody of the name.
     *
     * @param string $system "forge" or "gerrit"
     *
     * @return list<int>
     */
    public function numbersOf(string $key, string $system): array
    {
        $person = $this->personOf($key);
        if (null !== ($person[$system] ?? null)) {
            return [(int) $person[$system]];
        }
        $name = null === $person ? str_replace('_', ' ', $key) : $person['name'];
        if ('' === $name) {
            return [];
        }
        if (!isset($this->byName[$system])) {
            $this->byName[$system] = [];
            foreach ($this->people()['people'] as $other) {
                if (null !== $other[$system]) {
                    $this->byName[$system][$other['name']][] = (int) $other[$system];
                }
            }
        }

        return $this->byName[$system][$name] ?? [];
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
     * The people of documents, by their id.
     *
     * @param list<string> $ids
     *
     * @return array<string, User>
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return [];
        }
        $users = [];
        $results = $this->indices->users()->search(['query' => ['ids' => ['values' => $ids]], 'size' => count($ids)])->getResults();
        foreach ($results as $result) {
            $users[(string) $result->getId()] = User::fromDocument($result->getSource());
        }

        return $users;
    }

    /**
     * The people with the email among their addresses: more than one, if several accounts share it.
     *
     * @return list<User>
     */
    public function byEmail(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return [];
        }
        $results = $this->indices->users()->search(['query' => ['term' => ['email' => $email]], 'size' => 10])->getResults();

        return array_map(static fn (Result $result): User => User::fromDocument($result->getSource()), $results);
    }

    /**
     * The people of the person logged in: the account by its username, else the people with its email. Empty if
     * the index knows neither, or cannot be read: then the work is found by the name.
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
     * Notes a login with the TYPO3 account, see App\Subscriber\Typo3LoginSubscriber: the person of the username, or
     * the one without a username that has the email, gets the email of my.typo3.org and the time, and the username
     * as the id of its document. Writes nothing for a person the index does not know: the index holds the people of
     * Forge and Gerrit only.
     *
     * @return User|null the person written, null if none
     */
    public function recordLogin(string $username, string $email, \DateTimeImmutable $now): ?User
    {
        $username = mb_strtolower(trim($username));
        $email = mb_strtolower(trim($email));
        $user = self::loginTarget($username, $this->byUsername($username), $this->byEmail($email));
        if (null === $user) {
            return null;
        }
        $loggedIn = $user->loggedIn($username, $email, $now);
        $this->write([$loggedIn], [$user->id()]);

        return $loggedIn;
    }

    /**
     * The person a login with the TYPO3 account belongs to: the one with the username; else the one person with the
     * email that has no username yet, "forge:<number>" or "gerrit:<number>". None if the email is another
     * account's, or the address of several people without a username: which one it is, Forge tells, see
     * App\Import\ForgeUserSync.
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
     * Writes the Forge users the index does not have yet, empty but for their number and name: "Update Forge
     * users" fills them, see App\Import\ForgeUserSync. The importer calls it for every issue it writes, so it asks
     * the index once per process and then looks the numbers up in memory.
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
                $missing[$user->forgeId] = new User($user->forgeId, $user->name);
            }
        }
        $this->write(array_values($missing));

        return count($missing);
    }

    /**
     * Writes the Gerrit accounts the index does not have yet, as Gerrit tells them with a change, complete. A
     * person the index knows by the username, from Forge, gets the number on Gerrit added. Asks the index once per
     * process, as ensure().
     *
     * @param list<User> $users of Gerrit, see User::fromGerrit()
     *
     * @return int how many it wrote
     */
    public function ensureGerrit(array $users): int
    {
        $known = $this->known();
        $missing = [];
        foreach ($users as $user) {
            if (null !== $user->gerritId && !isset($known['gerrit:' . $user->gerritId])) {
                $missing[$user->gerritId] = $user;
            }
        }
        if ([] === $missing) {
            return 0;
        }
        $existing = $this->byIds(array_values(array_filter(array_map(static fn (User $user): ?string => $user->username, $missing))));
        $written = array_map(static fn (User $user): User => isset($existing[$user->id()]) ? $existing[$user->id()]->mergedWith($user) : $user, array_values($missing));
        $this->write($written);

        return count($written);
    }

    /**
     * All users with a Forge number.
     *
     * @return array<int, User> keyed by Forge number
     */
    public function all(): array
    {
        $users = [];
        foreach ($this->scroll(['exists' => ['field' => 'forge_id']]) as $user) {
            $users[(int) $user->forgeId] = $user;
        }

        return $users;
    }

    /**
     * How far the Forge users are, for the system page, see UserHealth: how many there are, have an avatar (an
     * email), were
     * never asked Forge for, are unknown to Forge, and when Forge was asked last. The people of Gerrit only are
     * complete with their change, they do not count.
     *
     * @return array{total: int, withAvatar: int, waiting: int, gone: int, lastFetched: \DateTimeImmutable|null}
     */
    public function statistics(): array
    {
        $result = $this->indices->users()->search([
            'size' => 0,
            'track_total_hits' => true,
            'query' => ['exists' => ['field' => 'forge_id']],
            'aggs' => [
                'withAvatar' => ['filter' => ['exists' => ['field' => 'email']]],
                'waiting' => ['filter' => ['bool' => ['must_not' => [['exists' => ['field' => 'fetched_on']]]]]],
                'gone' => ['filter' => ['term' => ['gone' => true]]],
                'lastFetched' => ['max' => ['field' => 'fetched_on']],
            ],
        ]);
        $aggregations = $result->getAggregations();
        $statistics = ['total' => $result->getTotalHits()];
        foreach (['withAvatar', 'waiting', 'gone'] as $name) {
            $statistics[$name] = (int) ($aggregations[$name]['doc_count'] ?? 0);
        }
        $last = $aggregations['lastFetched']['value'] ?? null;
        $statistics['lastFetched'] = null === $last ? null : new \DateTimeImmutable('@' . intdiv((int) $last, 1000));

        return $statistics;
    }

    /**
     * @param list<string> $ids
     */
    public function remove(array $ids): void
    {
        $this->write([], $ids);
    }

    /**
     * Writes users, and removes the documents they had under another id before: a user taken over from the issues
     * is "forge:<number>" until Forge told the username.
     *
     * @param list<User>   $users
     * @param list<string> $replaced ids of documents the users replace
     */
    public function write(array $users, array $replaced = []): void
    {
        $replaced = array_values(array_diff($replaced, array_map(static fn (User $user): string => $user->id(), $users)));
        if ([] === $users && [] === $replaced) {
            return;
        }
        $index = $this->indices->users();
        if ([] !== $users) {
            $index->addDocuments(array_map(static fn (User $user): Document => new Document($user->id(), $user->toDocument()), $users));
        }
        if ([] !== $replaced) {
            $index->deleteByQuery(new Query(['query' => ['ids' => ['values' => $replaced]]]));
        }
        $index->refresh();
        $this->cache->delete(self::AVATARS_CACHE_KEY);
        $this->cache->delete(self::PEOPLE_CACHE_KEY);
        $this->avatars = null;
        $this->people = null;
        $this->byName = [];
        if (null !== $this->known) {
            foreach ($users as $user) {
                $this->known += self::keys($user);
            }
        }
    }

    private function avatarBy(string $key, int $size): ?string
    {
        $hash = $this->avatars()[$key] ?? null;

        return null === $hash ? null : User::avatarUrl($hash, $size);
    }

    /**
     * @return array<string, true> "forge:<number>" and "gerrit:<number>" of the user
     */
    private static function keys(User $user): array
    {
        $keys = [];
        if (null !== $user->forgeId) {
            $keys['forge:' . $user->forgeId] = true;
        }
        if (null !== $user->gerritId) {
            $keys['gerrit:' . $user->gerritId] = true;
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return \Generator<User>
     */
    private function scroll(array $query): \Generator
    {
        $search = $this->indices->users()->createSearch(new Query(['size' => 1000, 'query' => $query]));
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
            foreach ($this->scroll(['match_all' => new \stdClass()]) as $user) {
                $this->known += self::keys($user);
            }
        }

        return $this->known;
    }

    /**
     * The id of the document by "forge:<number>" and "gerrit:<number>" ("keys"), and the people by the id.
     *
     * @return array{keys: array<string, string>, people: array<string, array{name: string, username: string|null, forge: int|null, gerrit: int|null}>}
     */
    private function people(): array
    {
        return $this->people ??= $this->cache->get(self::PEOPLE_CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::AVATARS_CACHE_LIFETIME);
            $people = ['keys' => [], 'people' => []];
            try {
                foreach ($this->scroll(['match_all' => new \stdClass()]) as $user) {
                    $id = $user->id();
                    foreach (array_keys(self::keys($user)) as $key) {
                        $people['keys'][$key] = $id;
                    }
                    $people['people'][$id] = ['name' => $user->name, 'username' => $user->username, 'forge' => $user->forgeId, 'gerrit' => $user->gerritId];
                }
            } catch (\Exception $e) {
                // The filters find the people by their numbers and names then; and ask again soon
                $this->logger->warning('The people could not be read: ' . $e->getMessage(), ['exception' => $e]);
                $item->expiresAfter(30);
            }

            return $people;
        });
    }

    /**
     * The hash of the avatar by "forge:<number>" and "gerrit:<number>", of the people with an email.
     *
     * A person of the issues Forge was not asked for yet has none, though its document of Gerrit has one: the two
     * are one when Forge told the username, see ForgeUserSync. Until then it takes the avatar of the one person of
     * the same name with one; with several of them, none.
     *
     * @param iterable<User> $users
     *
     * @return array<string, string>
     */
    public static function avatarMap(iterable $users): array
    {
        $avatars = [];
        $byName = [];
        $waiting = [];
        foreach ($users as $user) {
            $avatar = $user->avatar();
            if (null !== $avatar) {
                foreach (array_keys(self::keys($user)) as $key) {
                    $avatars[$key] = $avatar;
                }
                $byName[$user->name][$avatar] = true;
            } elseif (null !== $user->forgeId && null === $user->fetchedOn && '' !== $user->name) {
                $waiting['forge:' . $user->forgeId] = $user->name;
            }
        }
        foreach ($waiting as $key => $name) {
            if (!isset($avatars[$key]) && 1 === count($byName[$name] ?? [])) {
                $avatars[$key] = (string) array_key_first($byName[$name]);
            }
        }

        return $avatars;
    }

    /**
     * @return array<string, string> the hash of the avatar by "forge:<number>" and "gerrit:<number>"
     */
    private function avatars(): array
    {
        return $this->avatars ??= $this->cache->get(self::AVATARS_CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::AVATARS_CACHE_LIFETIME);
            $avatars = [];
            try {
                $avatars = self::avatarMap($this->scroll(['match_all' => new \stdClass()]));
            } catch (\Exception $e) {
                // The pages show no avatars then, no error; and ask again soon
                $this->logger->warning('The avatars could not be read: ' . $e->getMessage(), ['exception' => $e]);
                $item->expiresAfter(30);
            }

            return $avatars;
        });
    }
}
