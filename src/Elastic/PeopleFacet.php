<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

/**
 * The filters of people, of the issues and of the changes alike. A person is "filters[<key>][<person>]", by the id
 * of its document in the index "user", see Users::keyOf(): the username, the same on Forge and Gerrit, so a person
 * is one key in both; "forge:<number>" or "gerrit:<number>" while the index knows no username. A name, as the links
 * had them before, still filters by the name.
 *
 * The filters count by the number of the person on Forge ("forge": the issues) or on Gerrit ("gerrit": the changes),
 * not by the name: two people of one name are two, one person with a new name one. The latest document of each
 * person comes along, with the name and the fields the person is found by in the index "user", as a user of
 * templates/_macros/user.html.twig has them: "id" the number on Forge, "gerrit_id" the number on Gerrit, "email".
 * See user(); the search in the filter finds the others the same way, see PersonSuggestion.
 */
final class PeopleFacet
{
    /**
     * key: in "filters[<key>]"; system: of the number; number: its field; username: the field of the username, if
     * the documents have one; name: the field of the name, to filter by; label: where the document has the name.
     *
     * @var array<string, array{key: string, system: string, number: string, username: string|null, name: string, label: string, user: array<string, string>}>
     */
    public const array FILTERS = [
        'Author' => ['key' => 'author', 'system' => 'forge', 'number' => 'author.id', 'username' => null, 'name' => 'author.name', 'label' => 'author.name', 'user' => ['id' => 'author.id']],
        'Assigned to' => ['key' => 'assigned_to', 'system' => 'forge', 'number' => 'assigned_to.id', 'username' => null, 'name' => 'assigned_to.name.keyword', 'label' => 'assigned_to.name', 'user' => ['id' => 'assigned_to.id']],
        'Owner' => ['key' => 'owner', 'system' => 'gerrit', 'number' => 'owner_id', 'username' => 'owner_username', 'name' => 'owner', 'label' => 'owner', 'user' => ['gerrit_id' => 'owner_id', 'email' => 'ownerEmail']],
    ];

    /**
     * The title of the filter of people with the key, null for another filter.
     */
    public static function titleOf(string $key): ?string
    {
        return array_find_key(self::FILTERS, static fn (array $filter): bool => $filter['key'] === $key);
    }

    /**
     * The aggregation of the filter: the people with the most documents, by their number, each with its latest
     * document.
     *
     * @return array<string, mixed>
     */
    public static function aggregation(string $title, int $size = FacetSelection::SIZE): array
    {
        return FacetSelection::aggregation(self::FILTERS[$title]['number'], $size) + ['aggs' => ['user' => self::latest($title)]];
    }

    /**
     * The latest document of a person, with its name and the fields it is found by in the index "user".
     *
     * @return array<string, mixed>
     */
    public static function latest(string $title): array
    {
        $filter = self::FILTERS[$title];

        return ['top_hits' => ['size' => 1, '_source' => ['includes' => array_values(array_unique([...array_values($filter['user']), $filter['label']]))], 'sort' => [['updated_on' => 'desc']]]];
    }

    /**
     * The selected people of the filter by their numbers, see Users::numbersOf(): each number with the key it was
     * selected by. The aggregation of those the filter does not show among the most asks for the numbers, see
     * FacetSelection::withSelected(); their checkboxes are those of the keys, see keyed().
     *
     * @param list<string> $keys see FacetSelection::keys()
     *
     * @return array<int, string>
     */
    public static function selection(string $title, array $keys, Users $users): array
    {
        $selection = [];
        foreach ($keys as $key) {
            foreach ($users->numbersOf($key, self::FILTERS[$title]['system']) as $number) {
                $selection[$number] ??= $key;
            }
        }

        return $selection;
    }

    /**
     * The buckets of the filter with the keys of their people instead of the numbers, see Users::keyOf(): what the
     * checkboxes send. A selected person keeps the key it was selected by, so its checkbox is ticked.
     *
     * @param array<string, mixed> $aggregation the aggregation of the filter in the response
     * @param array<int, string>   $selection   see selection()
     *
     * @return array<string, mixed>
     */
    public static function keyed(array $aggregation, string $title, Users $users, array $selection = []): array
    {
        foreach ($aggregation['buckets'] ?? [] as $index => $bucket) {
            if (is_int($bucket['key'] ?? null)) {
                $aggregation['buckets'][$index]['key'] = $selection[$bucket['key']] ?? $users->keyOf(self::FILTERS[$title]['system'], $bucket['key']);
            }
        }

        return $aggregation;
    }

    /**
     * The query of the selected people of the filter, any of them: by their numbers, see Users::numbersOf(), and by
     * the username where the documents have one. A key the index knows nobody of is a name, as the links had them
     * before ("_" for the spaces).
     *
     * The changes imported before the number of their owner ("owner_id", ImportSchema::REVIEW 3) are found by the
     * name of the person, until they are loaded again.
     *
     * @param list<string> $keys see FacetSelection::keys()
     *
     * @return array<string, mixed>|null null without keys; a query that finds nothing for people without documents
     */
    public static function query(string $title, array $keys, Users $users): ?array
    {
        if ([] === $keys) {
            return null;
        }
        $filter = self::FILTERS[$title];
        $numbers = [];
        $usernames = [];
        $namesWithoutNumber = [];
        $names = [];
        foreach ($keys as $key) {
            $person = $users->personOf($key);
            $ofKey = $users->numbersOf($key, $filter['system']);
            array_push($numbers, ...$ofKey);
            if (null === $person) {
                if ([] === $ofKey) {
                    $names[] = str_replace('_', ' ', $key);
                }
                continue;
            }
            if (null !== $filter['username']) {
                if (null !== $person['username']) {
                    $usernames[] = $person['username'];
                }
                if ('' !== $person['name']) {
                    $namesWithoutNumber[] = $person['name'];
                }
            }
        }
        $should = [];
        if ([] !== $numbers) {
            $should[] = ['terms' => [$filter['number'] => array_values(array_unique($numbers))]];
        }
        if ([] !== $usernames) {
            $should[] = ['terms' => [(string) $filter['username'] => array_values(array_unique($usernames))]];
        }
        if ([] !== $namesWithoutNumber) {
            $should[] = ['bool' => [
                'must_not' => [['exists' => ['field' => $filter['number']]]],
                'filter' => [['terms' => [$filter['name'] => array_values(array_unique($namesWithoutNumber))]]],
            ]];
        }
        if ([] !== $names) {
            $should[] = ['terms' => [$filter['name'] => array_values(array_unique($names))]];
        }

        return [] === $should ? ['match_none' => new \stdClass()] : ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
    }

    /**
     * The person of a bucket of the filter or of the search in it, as the macros of the users take it: the name of
     * the latest document and what it has of the fields of the person. Null for a filter of no people.
     *
     * @param array<string, mixed> $bucket
     *
     * @return array<string, mixed>|null
     */
    public static function user(string $title, array $bucket): ?array
    {
        if (!isset(self::FILTERS[$title])) {
            return null;
        }
        $source = $bucket['user']['hits']['hits'][0]['_source'] ?? [];
        $name = self::valueAt($source, self::FILTERS[$title]['label']);
        $user = ['name' => is_string($name) && '' !== $name ? $name : (string) ($bucket['key'] ?? '')];
        foreach (self::FILTERS[$title]['user'] as $key => $field) {
            $value = self::valueAt($source, $field);
            if (null !== $value && '' !== $value) {
                $user[$key] = $value;
            }
        }

        return $user;
    }

    /**
     * The value at a dotted path like "assigned_to.id".
     *
     * @param array<string, mixed> $source
     */
    private static function valueAt(array $source, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($source) || !array_key_exists($key, $source)) {
                return null;
            }
            $source = $source[$key];
        }

        return $source;
    }
}
