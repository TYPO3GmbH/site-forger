<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Stream;

use App\Enum\StreamFigureEnum;
use App\People\Person;
use App\Search\LeaderboardQuery;
use App\Stream\LeaderboardRow;
use App\Stream\Leaderboards;
use App\Stream\LeaderboardSort;
use PHPUnit\Framework\TestCase;

final class LeaderboardsTest extends TestCase
{
    public function testTheAccountsOfOnePersonCountTogetherUnderTheUsername(): void
    {
        $now = self::people(
            forge: [self::account(3, 'Oliver Bartsch', 5, ['issues-created' => 5])],
            gerrit: [
                self::account(7, 'Jane Doe', 10, ['reviews' => 10], 'gerrit'),
                self::account(8, 'Oli Bartsch', 6, ['reviews' => 6], 'gerrit'),
            ],
        );

        $rows = self::rows($now, self::people(), '');

        self::assertSame(['obartsch', 'jdoe'], self::keys($rows));
        self::assertSame([1, 11, 6, 5], [
            $rows[0]->rank,
            $rows[0]->events,
            $rows[0]->figure(StreamFigureEnum::REVIEWS),
            $rows[0]->figure(StreamFigureEnum::ISSUES_CREATED),
        ]);
    }

    public function testTwoPeopleOfOneNameStayTwo(): void
    {
        $now = self::people(gerrit: [
            self::account(20, 'Max Muster', 4, [], 'gerrit'),
            self::account(21, 'Max Muster', 3, [], 'gerrit'),
        ]);

        self::assertSame(['gerrit:20', 'gerrit:21'], self::keys(self::rows($now, self::people(), '')));
    }

    public function testTheEventsWithoutAnAccountCountByName(): void
    {
        $now = self::people(names: [self::named('Old Name', 2)]);

        self::assertSame(['Old_Name'], self::keys(self::rows($now, self::people(), '')));
    }

    public function testItSortsByTheFigureAndTellsHowThePeopleMoved(): void
    {
        $now = self::people(gerrit: [
            self::account(7, 'Jane Doe', 10, ['reviews' => 2], 'gerrit'),
            self::account(9, 'John Roe', 4, ['reviews' => 4], 'gerrit'),
            self::account(10, 'Max New', 3, ['reviews' => 1], 'gerrit'),
        ]);
        $before = self::people(gerrit: [
            self::account(7, 'Jane Doe', 5, ['reviews' => 5], 'gerrit'),
            self::account(9, 'John Roe', 3, ['reviews' => 3], 'gerrit'),
            self::account(10, 'Max New', 1, [], 'gerrit'),
        ]);

        $rows = self::rows($now, $before, 'reviews');

        self::assertSame(
            ['John Roe', 'Jane Doe', 'Max New'],
            array_map(static fn (LeaderboardRow $row): string => $row->person->name, $rows),
        );
        self::assertSame(1, $rows[0]->moved());
        self::assertSame(-1, $rows[1]->moved());
        // No review before: no place to compare with
        self::assertNull($rows[2]->moved());
    }

    public function testTheLinesOfTheChangesMergedCountForTheOwner(): void
    {
        $now = self::people(
            forge: [self::account(3, 'Oliver Bartsch', 5, ['issues-created' => 5])],
            gerrit: [self::account(7, 'Jane Doe', 10, ['reviews' => 10], 'gerrit')],
        );
        $lines = ['buckets' => [self::owner(8, 'Oli Bartsch', 120, 30), self::owner(7, 'Jane Doe', 200, 5)]];

        $rows = self::rows($now, self::people(), 'removed', $lines);

        // The account on Gerrit joins the one on Forge, and the lines removed sort apart from the ones added
        self::assertSame(['obartsch', 'jdoe'], self::keys($rows));
        self::assertSame([120, 30, 150, 5], [
            $rows[0]->insertions,
            $rows[0]->deletions,
            $rows[0]->linesChanged(),
            $rows[0]->events,
        ]);
    }

    /**
     * @param array<string, mixed>      $now
     * @param array<string, mixed>      $before
     * @param array<string, mixed>|null $lines
     *
     * @return list<LeaderboardRow>
     */
    private static function rows(array $now, array $before, string $sort, ?array $lines = null): array
    {
        return Leaderboards::rows(
            ['people' => $now, 'lines' => $lines],
            ['people' => $before, 'lines' => null],
            LeaderboardSort::fromValue($sort),
            self::keyOf(...),
        );
    }

    /**
     * @param list<LeaderboardRow> $rows
     *
     * @return list<string>
     */
    private static function keys(array $rows): array
    {
        return array_map(static fn (LeaderboardRow $row): string => self::keyOf($row->person), $rows);
    }

    /**
     * Gerrit 8 and Forge 3 are the accounts of "obartsch". Like UserDirectory::keyOfPerson(), an account without
     * a username is its number, a person without an account its name.
     */
    private static function keyOf(Person $person): string
    {
        return match (true) {
            8 === $person->gerritId, 3 === $person->forgeId => 'obartsch',
            7 === $person->gerritId => 'jdoe',
            null !== $person->forgeId => 'forge:' . $person->forgeId,
            null !== $person->gerritId => 'gerrit:' . $person->gerritId,
            default => str_replace(' ', '_', $person->name),
        };
    }

    /**
     * @param list<array<string, mixed>> $forge
     * @param list<array<string, mixed>> $gerrit
     * @param list<array<string, mixed>> $names
     *
     * @return array<string, mixed>
     */
    private static function people(array $forge = [], array $gerrit = [], array $names = []): array
    {
        return [
            LeaderboardQuery::FORGE => ['buckets' => $forge],
            LeaderboardQuery::GERRIT => ['buckets' => $gerrit],
            LeaderboardQuery::NAMES => [LeaderboardQuery::NAMES => ['buckets' => $names]],
        ];
    }

    /**
     * @param array<string, int> $figures
     *
     * @return array<string, mixed>
     */
    private static function account(int $id, string $name, int $events, array $figures, string $system = 'forge'): array
    {
        return self::bucket($id, $events, $figures, ['name' => $name, $system . '_id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function named(string $name, int $events): array
    {
        return self::bucket($name, $events, [], ['name' => $name]);
    }

    /**
     * @param array<string, int>   $figures
     * @param array<string, mixed> $author
     *
     * @return array<string, mixed>
     */
    private static function bucket(int|string $key, int $events, array $figures, array $author): array
    {
        $bucket = [
            'key' => $key,
            'doc_count' => $events,
            LeaderboardQuery::LATEST => ['hits' => ['hits' => [['_source' => ['author' => $author]]]]],
        ];
        foreach ($figures as $figure => $count) {
            $bucket[$figure] = ['doc_count' => $count];
        }

        return $bucket;
    }

    /**
     * @return array<string, mixed>
     */
    private static function owner(int $gerritId, string $name, int $insertions, int $deletions): array
    {
        return [
            'key' => $gerritId,
            'doc_count' => 1,
            LeaderboardQuery::INSERTIONS => ['value' => (float) $insertions],
            LeaderboardQuery::DELETIONS => ['value' => (float) $deletions],
            LeaderboardQuery::LATEST => ['hits' => ['hits' => [
                ['_source' => ['owner' => $name, 'owner_id' => $gerritId]],
            ]]],
        ];
    }
}
