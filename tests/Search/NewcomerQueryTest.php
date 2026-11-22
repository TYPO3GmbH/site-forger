<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\StreamMilestoneEnum;
use App\Enum\StreamRangeEnum;
use App\Search\NewcomerQuery;
use App\Search\StreamWindow;
use PHPUnit\Framework\TestCase;

final class NewcomerQueryTest extends TestCase
{
    public function testEachFirstCountsByItsPersonAndItsDate(): void
    {
        $window = self::window();

        $issue = NewcomerQuery::request(StreamMilestoneEnum::FIRST_ISSUE, $window)['aggs'][NewcomerQuery::PEOPLE];
        $change = NewcomerQuery::request(StreamMilestoneEnum::FIRST_CHANGE, $window)['aggs'][NewcomerQuery::PEOPLE];

        self::assertSame('author.name', $issue['terms']['field']);
        self::assertSame(['min' => ['field' => 'created_on']], $issue['aggs'][NewcomerQuery::FIRST]);
        self::assertSame('owner', $change['terms']['field']);
        self::assertSame(['min' => ['field' => 'created_on']], $change['aggs'][NewcomerQuery::FIRST]);
    }

    public function testAFirstMergeIsTheFirstMergedChangeOfItsOwner(): void
    {
        $request = NewcomerQuery::request(StreamMilestoneEnum::FIRST_MERGE, self::window());

        self::assertSame(['term' => ['reviewstatus' => 'MERGED']], $request['query']);
        self::assertSame('owner', $request['aggs'][NewcomerQuery::PEOPLE]['terms']['field']);
    }

    public function testItKeepsThePeopleSinceTheWindowBefore(): void
    {
        $window = self::window();

        $request = NewcomerQuery::request(StreamMilestoneEnum::FIRST_CHANGE, $window);

        $since = $request['aggs'][NewcomerQuery::PEOPLE]['aggs']['since']['bucket_selector'];
        self::assertSame($window->previous()->from->getTimestamp() * 1000, $since['script']['params']['since']);
    }

    public function testTheDocumentsAreTheOldestOfEachPersonListed(): void
    {
        $request = NewcomerQuery::firstsRequest(StreamMilestoneEnum::FIRST_MERGE, ['Jane Doe', 'John Doe'], ['files']);

        // One document per owner, the oldest merged one
        self::assertSame(['field' => 'owner'], $request['collapse']);
        self::assertSame([['updated_on' => 'asc']], $request['sort']);
        self::assertSame(2, $request['size']);
        self::assertSame(
            [['term' => ['reviewstatus' => 'MERGED']], ['terms' => ['owner' => ['Jane Doe', 'John Doe']]]],
            $request['query']['bool']['filter'],
        );
        self::assertSame(['excludes' => ['files']], $request['_source']);
    }

    private static function window(): StreamWindow
    {
        $now = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('UTC'));

        return StreamWindow::ofRange(StreamRangeEnum::MONTH, $now);
    }
}
