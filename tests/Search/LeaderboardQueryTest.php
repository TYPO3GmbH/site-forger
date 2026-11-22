<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\StreamFigureEnum;
use App\Enum\StreamRangeEnum;
use App\Enum\StreamSourceEnum;
use App\Search\LeaderboardQuery;
use App\Search\StreamWindow;
use PHPUnit\Framework\TestCase;

final class LeaderboardQueryTest extends TestCase
{
    public function testItCountsPerAccountWithoutTools(): void
    {
        $request = LeaderboardQuery::request(self::window());

        self::assertContains(['term' => ['bot' => false]], $request['query']['bool']['filter']);
        $forge = $request['aggs'][LeaderboardQuery::FORGE];
        self::assertSame(['field' => 'author.forge_id', 'size' => LeaderboardQuery::ACCOUNTS], $forge['terms']);
        self::assertSame('author.gerrit_id', $request['aggs'][LeaderboardQuery::GERRIT]['terms']['field']);
        // Every figure, as the statistics count it
        foreach (StreamFigureEnum::cases() as $figure) {
            self::assertArrayHasKey($figure->value, $forge['aggs']);
        }
    }

    public function testASourceCountsOnlyItsEvents(): void
    {
        $filters = LeaderboardQuery::request(self::window(), StreamSourceEnum::GERRIT)['query']['bool']['filter'];

        self::assertContains(['term' => ['source' => 'gerrit']], $filters);
        self::assertNotContains(
            ['term' => ['source' => 'gerrit']],
            LeaderboardQuery::request(self::window())['query']['bool']['filter'],
        );
    }

    public function testOnlyTheEventsWithoutAnAccountCountByName(): void
    {
        $names = LeaderboardQuery::request(self::window())['aggs'][LeaderboardQuery::NAMES];

        self::assertSame(
            [['exists' => ['field' => 'author.forge_id']], ['exists' => ['field' => 'author.gerrit_id']]],
            $names['filter']['bool']['must_not'],
        );
        self::assertSame('author.name', $names['aggs'][LeaderboardQuery::NAMES]['terms']['field']);
    }

    public function testTheLinesAreThoseOfTheChangesMergedInTheWindowPerOwner(): void
    {
        $request = LeaderboardQuery::linesRequest(self::window());

        $filters = $request['query']['bool']['filter'];
        self::assertSame(['term' => ['reviewstatus' => 'MERGED']], $filters[0]);
        self::assertArrayHasKey('closed_on', $filters[1]['range']);
        $owners = $request['aggs'][LeaderboardQuery::OWNERS];
        self::assertSame('owner_id', $owners['terms']['field']);
        self::assertSame(['sum' => ['field' => 'insertions']], $owners['aggs'][LeaderboardQuery::INSERTIONS]);
    }

    private static function window(): StreamWindow
    {
        $now = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('UTC'));

        return StreamWindow::ofRange(StreamRangeEnum::MONTH, $now);
    }
}
