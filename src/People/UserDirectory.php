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
use App\Repository\PersonLinkRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The people of the index "user" as the pages show them: their avatars and their keys in the filters of people.
 *
 * The issues keep only the number and the name of their author and assignee. The changes keep the number of their
 * owner on Gerrit. The pages look up the rest here. A page shows dozens of people, so the lookups are cached.
 *
 * An admin can link accounts to a person, see App\Entity\PersonLink: an account on Forge or Gerrit, or the person
 * of another TYPO3 account. The account then counts as the person's everywhere: its keys lead to the person, and
 * numbersOf() gives its numbers with the person's.
 *
 * @phpstan-type Entry array{name: string, names: list<string>, username: string|null, forge: int|null,
 *     gerrit: int|null, linked: array{forge: list<int>, gerrit: list<int>}}
 * @phpstan-type People array{keys: array<string, string>, people: array<string, Entry>,
 *     aliases: array<string, string>}
 */
class UserDirectory
{
    private const string AVATARS_CACHE_KEY = 'user_avatars';
    // The version changes with what it keeps of a person
    public const string PEOPLE_CACHE_KEY = 'user_people_3';
    private const int CACHE_LIFETIME = 600;

    /**
     * When the index cannot be read, the next request asks again after this many seconds.
     */
    private const int RETRY_AFTER = 30;

    /** @var array<string, string>|null */
    private ?array $avatars = null;

    /**
     * @var People|null
     */
    private ?array $people = null;

    /**
     * The numbers of the people by "forge" or "gerrit" and their name, see numbersOf().
     *
     * @var array<string, array<string, list<int>>>
     */
    private array $byName = [];

    public function __construct(
        private readonly Indices $indices,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ?PersonLinkRepository $links = null,
    ) {
    }

    /**
     * The address of the avatar of a person, found by the number on Forge or on Gerrit. Changes imported before the
     * number of their owner have only the email. Null if no avatar is known.
     */
    public function avatarOfPerson(?Person $person, int $size): ?string
    {
        if (null !== $person?->forgeId) {
            return $this->avatarOf($person->forgeId, $size);
        }
        if (null !== ($url = $this->gerritAvatarOf($person?->gerritId, $size))) {
            return $url;
        }
        $email = mb_strtolower(trim((string) $person?->email));

        return str_contains($email, '@') ? User::avatarUrl(User::avatarHash($email), $size) : null;
    }

    public function avatarOf(?int $forgeId, int $size): ?string
    {
        return null === $forgeId ? null : $this->avatarBy('forge:' . $forgeId, $size);
    }

    public function gerritAvatarOf(?int $gerritId, int $size): ?string
    {
        return null === $gerritId ? null : $this->avatarBy('gerrit:' . $gerritId, $size);
    }

    /**
     * The key of a person in the filters of people, "filters[author][<key>]", see PeopleFacet.
     * It is the id of the document of the person, the username. While the index has no document, it is
     * "forge:<number>" or "gerrit:<number>".
     *
     * @param string $system "forge" or "gerrit"
     */
    public function keyOf(string $system, int $number): string
    {
        $key = $system . ':' . $number;

        return $this->people()['keys'][$key] ?? $key;
    }

    /**
     * The key of a person in the filters of people, see keyOf(). Without a number on Forge or Gerrit it is the name
     * with "_" for the spaces, as the filters had it before.
     */
    public function keyOfPerson(?Person $person): string
    {
        if (null !== $person?->forgeId) {
            return $this->keyOf('forge', $person->forgeId);
        }
        if (null !== $person?->gerritId) {
            return $this->keyOf('gerrit', $person->gerritId);
        }

        return str_replace(' ', '_', trim((string) $person?->name));
    }

    /**
     * The person as every page and the API show it: the name, the username and both numbers of the TYPO3 account,
     * the name of Gerrit first, see User::displayName(). An event or a document only has the name of its system,
     * like "Ge Rie" of Forge for "Georg Ringer". A person the index of the users does not know stays as it is.
     */
    public function shown(?Person $person): ?Person
    {
        if (null === $person || (null === $person->forgeId && null === $person->gerritId)) {
            return $person;
        }
        $known = $this->personOf($this->keyOfPerson($person));
        if (null === $known || '' === $known->name) {
            return $person;
        }

        return new Person(
            $known->name,
            forgeId: $known->forgeId ?? $person->forgeId,
            gerritId: $known->gerritId ?? $person->gerritId,
            email: $person->email,
            username: $known->username ?? $person->username,
        );
    }

    /**
     * The person of a key of the filters of people, see keyOf().
     * A key "forge:<number>" without a document gives the number alone. A key that is no person's is a name, as
     * the links had them before: then it is null.
     */
    public function personOf(string $key): ?Person
    {
        $people = $this->people();
        if (1 === preg_match('/^(forge|gerrit):(\d+)$/', $key, $matches)) {
            $id = $people['keys'][$key] ?? null;
            if (null !== $id) {
                return self::person($people['people'][$id]);
            }
            $number = (int) $matches[2];

            return 'forge' === $matches[1] ? new Person('', forgeId: $number) : new Person('', gerritId: $number);
        }
        $person = $this->entryOf($key);

        return null === $person ? null : self::person($person);
    }

    /**
     * The person of a key that is the id of a document, or of a document linked to a person.
     *
     * @return Entry|null
     */
    private function entryOf(string $key): ?array
    {
        $people = $this->people();
        $id = mb_strtolower($key);

        return $people['people'][$people['aliases'][$id] ?? $id] ?? null;
    }

    /**
     * The key to keep for a person, see keyOf(): given as "forge:<number>", "gerrit:<number>" or the username.
     * Null when the value names no person, like the words of a name.
     */
    public function storedKeyOf(string $value): ?string
    {
        $value = mb_strtolower(trim($value));
        if (1 === preg_match('/^(forge|gerrit):(\d{1,9})$/', $value, $matches)) {
            return $this->keyOf($matches[1], (int) $matches[2]);
        }

        return null === $this->personOf($value) ? null : $value;
    }

    /**
     * Every key of the person of a key: the key itself, "forge:<number>" and "gerrit:<number>". A link names a
     * person by any of them.
     *
     * @return list<string>
     */
    public function keysOf(string $key): array
    {
        $keys = [$key];
        foreach (['forge', 'gerrit'] as $system) {
            foreach ($this->numbersOf($key, $system) as $number) {
                $keys[] = $system . ':' . $number;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The numbers on Forge or Gerrit of a key of the filters of people.
     * Without a number of the person, these are the numbers of the people of the same name. The index has them
     * until ForgeUserSync joins the person of Gerrit and the one of the issues. A key that is no person's is a
     * name, as the links had them before.
     *
     * @param string $system "forge" or "gerrit"
     *
     * @return list<int>
     */
    public function numbersOf(string $key, string $system): array
    {
        $person = $this->personOf($key);
        $numbers = $this->accountNumbers($key, $system);
        if ([] !== $numbers) {
            return $numbers;
        }
        $name = null === $person ? str_replace('_', ' ', $key) : $person->name;
        if ('' === $name) {
            return [];
        }
        if (!isset($this->byName[$system])) {
            $this->byName[$system] = [];
            foreach ($this->people()['people'] as $other) {
                if (null === $other[$system]) {
                    continue;
                }
                // A person is found by each name: the one shown and the one of Forge
                foreach ($other['names'] as $otherName) {
                    $this->byName[$system][$otherName][] = (int) $other[$system];
                }
            }
        }

        return $this->byName[$system][$name] ?? [];
    }

    /**
     * The numbers of the accounts of the person of a key in a system: its own and the ones linked to it.
     *
     * @param string $system "forge" or "gerrit"
     *
     * @return list<int>
     */
    private function accountNumbers(string $key, string $system): array
    {
        if (1 === preg_match('/^(forge|gerrit):(\d+)$/', $key, $matches)) {
            $id = $this->people()['keys'][$key] ?? null;
            if (null === $id) {
                return $matches[1] === $system ? [(int) $matches[2]] : [];
            }
            $key = $id;
        }
        $entry = $this->entryOf($key);
        if (null === $entry) {
            return [];
        }

        $forge = 'forge' === $system;
        $numbers = [];
        foreach ([
            $forge ? $entry['forge'] : $entry['gerrit'],
            ...($forge ? $entry['linked']['forge'] : $entry['linked']['gerrit']),
        ] as $number) {
            if (null !== $number && !in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }

        return $numbers;
    }

    /**
     * Forgets what it read, after the people changed, see UserRepository::write().
     */
    public function forget(): void
    {
        $this->cache->delete(self::AVATARS_CACHE_KEY);
        $this->cache->delete(self::PEOPLE_CACHE_KEY);
        $this->avatars = null;
        $this->people = null;
        $this->byName = [];
    }

    /**
     * The hash of the avatar by "forge:<number>" and "gerrit:<number>", of the people with an email.
     *
     * A person of the issues that Forge was not asked for yet has no avatar, though its document of Gerrit has one.
     * The two become one when Forge tells the username, see ForgeUserSync. Until then the person takes the avatar
     * of the one person of the same name with an avatar. With several of them it gets none.
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
                foreach ($user->keys() as $key) {
                    $avatars[$key] = $avatar;
                }
                foreach ($user->names() as $name) {
                    $byName[$name][$avatar] = true;
                }
            } elseif (null !== $user->forgeId && null === $user->fetchedOn && '' !== $user->forgeName) {
                $waiting['forge:' . $user->forgeId] = $user->forgeName;
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
     * @param Entry $person see people()
     */
    private static function person(array $person): Person
    {
        return new Person(
            $person['name'],
            forgeId: $person['forge'],
            gerritId: $person['gerrit'],
            username: $person['username'],
        );
    }

    /**
     * @param Entry $entry
     *
     * @return Entry
     */
    private static function withNumber(array $entry, string $system, int $number): array
    {
        $linked = $entry['linked'];
        if ('forge' === $system) {
            $linked['forge'][] = $number;
        } else {
            $linked['gerrit'][] = $number;
        }
        $entry['linked'] = $linked;

        return $entry;
    }

    /**
     * The person with the numbers and names of a document linked to it.
     *
     * @param Entry $entry
     * @param Entry $document
     *
     * @return Entry
     */
    private static function withDocument(array $entry, array $document): array
    {
        $numbers = static fn (?int $own, array $linked): array => array_values(array_filter(
            [$own, ...$linked],
            static fn (?int $number): bool => null !== $number,
        ));
        $entry['linked'] = [
            'forge' => [...$entry['linked']['forge'], ...$numbers($document['forge'], $document['linked']['forge'])],
            'gerrit' => [
                ...$entry['linked']['gerrit'],
                ...$numbers($document['gerrit'], $document['linked']['gerrit']),
            ],
        ];
        $entry['names'] = array_values(array_unique([...$entry['names'], ...$document['names']]));

        return $entry;
    }

    private function avatarBy(string $key, int $size): ?string
    {
        $hash = $this->avatars()[$key] ?? null;

        return null === $hash ? null : User::avatarUrl($hash, $size);
    }

    /**
     * The id of the document by "forge:<number>" and "gerrit:<number>" ("keys"), the people by the id, and the
     * person of each document linked to another ("aliases"), see linked().
     *
     * @return People
     */
    private function people(): array
    {
        return $this->people ??= $this->cache->get(self::PEOPLE_CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_LIFETIME);
            $people = ['keys' => [], 'people' => [], 'aliases' => []];
            try {
                foreach ($this->everyone() as $user) {
                    $id = $user->id();
                    foreach ($user->keys() as $key) {
                        $people['keys'][$key] = $id;
                    }
                    $people['people'][$id] = [
                        'name' => $user->displayName(),
                        'names' => $user->names(),
                        'username' => $user->username,
                        'forge' => $user->forgeId,
                        'gerrit' => $user->gerritId,
                        'linked' => ['forge' => [], 'gerrit' => []],
                    ];
                }
                $people = self::linked($people, $this->links?->persons() ?? []);
            } catch (\Exception $e) {
                // The filters find the people by their numbers and names then
                $this->logger->warning('The people could not be read: ' . $e->getMessage(), ['exception' => $e]);
                $item->expiresAfter(self::RETRY_AFTER);
            }

            return $people;
        });
    }

    /**
     * The people with the accounts an admin linked to them: the document of an account goes up in the person, its
     * keys lead to the person, its numbers count as the person's. An account of no document still leads to the
     * person by its key. A link to a person the index does not know does nothing.
     *
     * @param People                $people
     * @param array<string, string> $links  the username of the person by the key of the account
     *
     * @return People
     */
    public static function linked(array $people, array $links): array
    {
        foreach ($links as $account => $person) {
            $target = $people['people'][$person] ?? null;
            if (null === $target) {
                continue;
            }
            $source = $people['keys'][$account] ?? (isset($people['people'][$account]) ? $account : null);
            if (null === $source) {
                // An account of no document: its key leads to the person
                if (1 === preg_match('/^(forge|gerrit):(\d+)$/', $account, $matches)) {
                    $people['keys'][$account] = $person;
                    $people['people'][$person] = self::withNumber($target, $matches[1], (int) $matches[2]);
                }
                continue;
            }
            if ($source === $person) {
                continue;
            }
            $people['people'][$person] = self::withDocument($target, $people['people'][$source]);
            unset($people['people'][$source]);
            $people['keys'] = array_map(
                static fn (string $id): string => $id === $source ? $person : $id,
                $people['keys'],
            );
            $people['aliases'] = array_map(
                static fn (string $id): string => $id === $source ? $person : $id,
                $people['aliases'],
            );
            $people['aliases'][$source] = $person;
        }

        return $people;
    }

    /**
     * @return array<string, string> the hash of the avatar by "forge:<number>" and "gerrit:<number>"
     */
    private function avatars(): array
    {
        return $this->avatars ??= $this->cache->get(self::AVATARS_CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_LIFETIME);
            $avatars = [];
            try {
                $avatars = self::avatarMap($this->everyone());
            } catch (\Exception $e) {
                // The pages show no avatars then, no error
                $this->logger->warning('The avatars could not be read: ' . $e->getMessage(), ['exception' => $e]);
                $item->expiresAfter(self::RETRY_AFTER);
            }

            return $avatars;
        });
    }

    /**
     * @return \Generator<User>
     */
    private function everyone(): \Generator
    {
        return UserRepository::documents($this->indices->users(), ['match_all' => new \stdClass()]);
    }
}
