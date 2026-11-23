<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\People\Person;
use App\People\UserDirectory;

/**
 * The people a sprint board is shared with, as the field "People" of its form takes and shows them, see
 * BoardAccessFields: keys of the search of people or usernames, separated by commas.
 */
final readonly class BoardMembers
{
    public function __construct(
        private UserDirectory $users,
    ) {
    }

    /**
     * The usernames of the people of the field "People" of the form of a board: keys of the search of people, like
     * "forge:128", or usernames, separated by commas. "unknown" are those without a TYPO3 account Forger knows.
     *
     * @return array{usernames: list<string>, unknown: list<string>}
     */
    public function resolve(string $field): array
    {
        $usernames = [];
        $unknown = [];
        foreach (array_filter(array_map(trim(...), explode(',', $field))) as $value) {
            $key = $this->users->storedKeyOf($value);
            $username = null === $key ? null : $this->users->personOf($key)?->username;
            if (null === $username || '' === $username) {
                $unknown[] = $value;
            } else {
                $usernames[] = mb_strtolower($username);
            }
        }

        return ['usernames' => array_values(array_unique($usernames)), 'unknown' => $unknown];
    }

    /**
     * The people of the field "People" as the form shows them: each value with the person Forger knows by it, for
     * the name and the avatar, or none.
     *
     * @return list<array{key: string, person: Person|null}>
     */
    public function shown(string $field): array
    {
        $shown = [];
        foreach (array_filter(array_map(trim(...), explode(',', $field))) as $value) {
            $key = $this->users->storedKeyOf($value);
            $shown[] = ['key' => $value, 'person' => null === $key ? null : $this->users->personOf($key)];
        }

        return $shown;
    }
}
