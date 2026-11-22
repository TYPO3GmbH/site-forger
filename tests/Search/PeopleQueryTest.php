<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\StreamRangeEnum;
use App\Search\PeopleQuery;
use App\Search\StreamWindow;
use PHPUnit\Framework\TestCase;

final class PeopleQueryTest extends TestCase
{
    public function testASearchNeedsTwoCharacters(): void
    {
        self::assertNull(PeopleQuery::search(' k '));
    }

    public function testItSearchesPartsOfTheNameAndTheStartOfTheUsername(): void
    {
        $request = PeopleQuery::search('Ko*tt');

        self::assertIsArray($request);
        $should = $request['query']['bool']['should'];
        self::assertSame('*ko\\*tt*', $should[0]['wildcard']['author.name']['value']);
        self::assertTrue($should[0]['wildcard']['author.name']['case_insensitive']);
        self::assertSame('ko*tt', $should[1]['prefix']['author.username']['value']);
        self::assertSame([['term' => ['bot' => false]]], $request['query']['bool']['filter']);
        self::assertSame(PeopleQuery::SIZE, $request['aggs'][PeopleQuery::PEOPLE]['terms']['size']);
    }

    public function testTheAccountsOfAUsernameFindThePersonToo(): void
    {
        $request = PeopleQuery::search('jdoe', [7], [9]);

        self::assertIsArray($request);
        $should = $request['query']['bool']['should'];
        self::assertContains(['terms' => ['author.forge_id' => [7]]], $should);
        self::assertContains(['terms' => ['author.gerrit_id' => [9]]], $should);
    }

    public function testTheActivityCountsTheEventsOfEachPersonAsTheStatistics(): void
    {
        $now = new \DateTimeImmutable('2026-11-22 12:00:00', new \DateTimeZone('UTC'));
        $request = PeopleQuery::activity(
            ['jdoe' => ['forge' => [7], 'gerrit' => []], 'nobody' => ['forge' => [], 'gerrit' => []]],
            StreamWindow::ofRange(StreamRangeEnum::HALF_YEAR, $now),
        );

        self::assertContains(
            ['range' => ['date' => ['gte' => '2026/05/23 00:00:00', 'lt' => '2026/11/22 12:00:00']]],
            $request['query']['bool']['filter'],
        );
        $people = $request['aggs'][PeopleQuery::PEOPLE];
        self::assertSame(
            ['bool' => ['should' => [['terms' => ['author.forge_id' => [7]]]], 'minimum_should_match' => 1]],
            $people['filters']['filters']['jdoe'],
        );
        self::assertEquals(['match_none' => new \stdClass()], $people['filters']['filters']['nobody']);
        self::assertSame('week', $people['aggs'][PeopleQuery::WEEKS]['date_histogram']['calendar_interval']);
        self::assertSame(
            ['bool' => ['filter' => [
                ['term' => ['source' => 'gerrit']],
                ['terms' => ['kind' => ['voted', 'commented']]],
            ]]],
            $people['aggs'][PeopleQuery::REVIEWS]['filter']['bool']['should'][0],
        );
        $patches = $people['aggs'][PeopleQuery::PATCHES]['filter']['bool']['should'];
        self::assertSame(['terms' => ['kind' => ['created']]], $patches[0]['bool']['filter'][1]);
        self::assertSame(['terms' => ['kind' => ['uploaded']]], $patches[1]['bool']['filter'][1]);
        self::assertSame(
            ['term' => ['source' => 'forge']],
            $people['aggs'][PeopleQuery::ISSUES]['filter']['bool']['should'][0]['bool']['filter'][0],
        );
    }
}
