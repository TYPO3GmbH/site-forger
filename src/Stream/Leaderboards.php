<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Document\StreamEvent;
use App\Elastic\Bucket;
use App\Enum\StreamFigureEnum;
use App\People\Person;
use App\Search\LeaderboardQuery;

/**
 * Reads the answers of LeaderboardQuery, so tests need no Elasticsearch. Elasticsearch counts the events per account
 * on Forge and on Gerrit, the lines per owner on Gerrit. It sums all of them up under the key of the person, the
 * TYPO3 username, as the filter of a person in the statistics does, see App\People\UserDirectory::keyOfPerson().
 * An account the index of the users does not know the username of counts under its number until it does.
 *
 * @phpstan-type Entry array{person: Person, events: int, figures: array<string, int>, insertions: int, deletions: int}
 */
final class Leaderboards
{
    /**
     * The aggregations of a window: those of LeaderboardQuery::request(), and OWNERS of
     * LeaderboardQuery::linesRequest().
     *
     * @param array{people: array<string, mixed>, lines: array<string, mixed>|null} $now
     * @param array{people: array<string, mixed>, lines: array<string, mixed>|null} $before
     * @param \Closure(Person): string                                              $keyOf  the key of a person
     *
     * @return list<LeaderboardRow> the first LeaderboardQuery::SIZE
     */
    public static function rows(array $now, array $before, LeaderboardSort $sort, \Closure $keyOf): array
    {
        // Without anything to count before, a person has no place there: new
        $previousRanks = [];
        foreach (self::ranked(self::joined($before, $keyOf), $sort) as $index => [$key, $row]) {
            if ($sort->countOf($row) > 0) {
                $previousRanks[$key] = $index + 1;
            }
        }
        $rows = [];
        $ranked = array_slice(self::ranked(self::joined($now, $keyOf), $sort), 0, LeaderboardQuery::SIZE);
        foreach ($ranked as $index => [$key, $row]) {
            $rows[] = new LeaderboardRow(
                $index + 1,
                $row->person,
                $row->events,
                $row->figures,
                $previousRanks[$key] ?? null,
                $row->insertions,
                $row->deletions,
            );
        }

        return $rows;
    }

    /**
     * The accounts of one person summed up under the key of the person. The person is the one of the account counted
     * first.
     *
     * @param array{people: array<string, mixed>, lines: array<string, mixed>|null} $aggregations
     * @param \Closure(Person): string                                              $keyOf
     *
     * @return array<string, Entry>
     */
    private static function joined(array $aggregations, \Closure $keyOf): array
    {
        $people = $aggregations['people'];
        $accounts = [
            ...Bucket::listOf($people[LeaderboardQuery::FORGE] ?? null),
            ...Bucket::listOf($people[LeaderboardQuery::GERRIT] ?? null),
            ...Bucket::listOf($people[LeaderboardQuery::NAMES][LeaderboardQuery::NAMES] ?? null),
        ];
        $joined = [];
        foreach ($accounts as $bucket) {
            $person = StreamEvent::fromDocument($bucket->topHit(LeaderboardQuery::LATEST))->author;
            if (null === $person) {
                continue;
            }
            $key = $keyOf($person);
            $entry = $joined[$key] ?? self::entry($person);
            $entry['events'] += $bucket->count;
            foreach (StreamFigureEnum::cases() as $figure) {
                $entry['figures'][$figure->value] += $bucket->countOf($figure->value);
            }
            $joined[$key] = $entry;
        }
        foreach (Bucket::listOf($aggregations['lines']) as $bucket) {
            $owner = self::owner($bucket);
            if (null === $owner) {
                continue;
            }
            $key = $keyOf($owner);
            $entry = $joined[$key] ?? self::entry($owner);
            $entry['insertions'] += self::sum($bucket, LeaderboardQuery::INSERTIONS);
            $entry['deletions'] += self::sum($bucket, LeaderboardQuery::DELETIONS);
            $joined[$key] = $entry;
        }

        return $joined;
    }

    /**
     * @return Entry
     */
    private static function entry(Person $person): array
    {
        return [
            'person' => $person,
            'events' => 0,
            'figures' => array_fill_keys(
                array_map(static fn (StreamFigureEnum $figure): string => $figure->value, StreamFigureEnum::cases()),
                0,
            ),
            'insertions' => 0,
            'deletions' => 0,
        ];
    }

    /**
     * The owner of the changes of a bucket of LeaderboardQuery::OWNERS, as its latest change names it.
     */
    private static function owner(Bucket $bucket): ?Person
    {
        $change = $bucket->topHit(LeaderboardQuery::LATEST);
        $name = trim((string) ($change['owner'] ?? ''));

        return '' === $name ? null : new Person(
            $name,
            gerritId: isset($change['owner_id']) ? (int) $change['owner_id'] : null,
            username: isset($change['owner_username']) ? (string) $change['owner_username'] : null,
        );
    }

    private static function sum(Bucket $bucket, string $name): int
    {
        return (int) round((float) ($bucket->aggregation($name)['value'] ?? 0));
    }

    /**
     * The places, the most of the sort first, by name when the counts are equal.
     *
     * @param array<string, Entry> $joined
     *
     * @return list<array{0: string, 1: LeaderboardRow}> the key and the place of each person, the rank not set
     */
    private static function ranked(array $joined, LeaderboardSort $sort): array
    {
        $ranked = [];
        foreach ($joined as $key => $entry) {
            $ranked[] = [(string) $key, new LeaderboardRow(
                0,
                $entry['person'],
                $entry['events'],
                $entry['figures'],
                null,
                $entry['insertions'],
                $entry['deletions'],
            )];
        }
        usort($ranked, static fn (array $a, array $b): int => [$sort->countOf($b[1]), $a[1]->person->name]
            <=> [$sort->countOf($a[1]), $b[1]->person->name]);

        return $ranked;
    }
}
