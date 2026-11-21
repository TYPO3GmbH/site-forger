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
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The people of the index "user" as the pages show them: their avatars and their keys in the filters of people.
 *
 * The issues keep only the number and the name of their author and assignee. The changes keep the number of their
 * owner on Gerrit. The pages look up the rest here. A page shows dozens of people, so the lookups are cached.
 */
class UserDirectory
{
    private const string AVATARS_CACHE_KEY = 'user_avatars';
    private const string PEOPLE_CACHE_KEY = 'user_people';
    private const int CACHE_LIFETIME = 600;

    /**
     * When the index cannot be read, the next request asks again after this many seconds.
     */
    private const int RETRY_AFTER = 30;

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

    public function __construct(
        private readonly Indices $indices,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
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
        $person = $people['people'][mb_strtolower($key)] ?? null;

        return null === $person ? null : self::person($person);
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
        $number = 'forge' === $system ? $person?->forgeId : $person?->gerritId;
        if (null !== $number) {
            return [$number];
        }
        $name = null === $person ? str_replace('_', ' ', $key) : $person->name;
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
     * @param array{name: string, username: string|null, forge: int|null, gerrit: int|null} $person see people()
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

    private function avatarBy(string $key, int $size): ?string
    {
        $hash = $this->avatars()[$key] ?? null;

        return null === $hash ? null : User::avatarUrl($hash, $size);
    }

    /**
     * The id of the document by "forge:<number>" and "gerrit:<number>" ("keys"), and the people by the id.
     *
     * @return array{keys: array<string, string>, people: array<string, array{name: string, username: string|null, forge: int|null, gerrit: int|null}>}
     */
    private function people(): array
    {
        return $this->people ??= $this->cache->get(self::PEOPLE_CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_LIFETIME);
            $people = ['keys' => [], 'people' => []];
            try {
                foreach ($this->everyone() as $user) {
                    $id = $user->id();
                    foreach ($user->keys() as $key) {
                        $people['keys'][$key] = $id;
                    }
                    $people['people'][$id] = [
                        'name' => $user->name,
                        'username' => $user->username,
                        'forge' => $user->forgeId,
                        'gerrit' => $user->gerritId,
                    ];
                }
            } catch (\Exception $e) {
                // The filters find the people by their numbers and names then
                $this->logger->warning('The people could not be read: ' . $e->getMessage(), ['exception' => $e]);
                $item->expiresAfter(self::RETRY_AFTER);
            }

            return $people;
        });
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
