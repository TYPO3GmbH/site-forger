<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Stream;

use App\Search\PeopleQuery;
use App\Stream\StreamPeople;
use PHPUnit\Framework\TestCase;

final class StreamPeopleTest extends TestCase
{
    public function testItReadsThePeopleFoundByTheirLatestEvent(): void
    {
        $people = StreamPeople::found(['buckets' => [
            self::bucket('Jane Doe', ['author' => ['name' => 'Jane Doe', 'forge_id' => 7, 'username' => 'jdoe']]),
            self::bucket('', ['author' => ['name' => '']]),
            self::bucket('John Roe', ['author' => ['name' => 'John Roe', 'gerrit_id' => 9]]),
        ]]);

        self::assertCount(2, $people);
        self::assertSame('Jane Doe', $people[0]->name);
        self::assertSame(7, $people[0]->forgeId);
        self::assertSame(9, $people[1]->gerritId);
    }

    public function testItReadsWhatEachPersonDidPerWeek(): void
    {
        $activity = StreamPeople::activity(
            ['buckets' => [
                'jdoe' => [
                    'doc_count' => 5,
                    PeopleQuery::REVIEWS => ['doc_count' => 3],
                    PeopleQuery::PATCHES => ['doc_count' => 1],
                    PeopleQuery::ISSUES => ['doc_count' => 2],
                    PeopleQuery::COMMENTS => ['doc_count' => 4],
                    PeopleQuery::WEEKS => ['buckets' => [
                        ['key_as_string' => '2026-11-16', 'doc_count' => 5],
                    ]],
                ],
                'forge:7' => ['doc_count' => 0],
            ]],
            ['2026-11-09', '2026-11-16'],
        );

        self::assertSame(3, $activity['jdoe']->reviews);
        self::assertSame(1, $activity['jdoe']->patches);
        self::assertSame(2, $activity['jdoe']->issues);
        self::assertSame(4, $activity['jdoe']->comments);
        self::assertSame([0, 5], $activity['jdoe']->weeks);
        self::assertSame([0, 0], $activity['forge:7']->weeks);
    }

    /**
     * A bucket of the aggregation PeopleQuery::PEOPLE with its latest event.
     *
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private static function bucket(string $key, array $source): array
    {
        return [
            'key' => $key,
            'doc_count' => 1,
            PeopleQuery::LATEST => ['hits' => ['hits' => [['_source' => $source]]]],
        ];
    }
}
