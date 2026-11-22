<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\StreamKindEnum;
use App\Enum\StreamSourceEnum;
use App\Search\StreamCursor;
use App\Search\StreamFilter;
use App\Search\StreamQuery;
use PHPUnit\Framework\TestCase;

final class StreamQueryTest extends TestCase
{
    public function testWithoutAFilterItHidesTheBots(): void
    {
        self::assertSame(
            ['bool' => ['filter' => [['term' => ['bot' => false]]]]],
            StreamQuery::query(new StreamFilter()),
        );
    }

    public function testItFiltersTheChannelTheKindsAndThePerson(): void
    {
        $query = StreamQuery::query(new StreamFilter(
            source: StreamSourceEnum::GERRIT,
            kinds: [StreamKindEnum::VOTED, StreamKindEnum::MERGED],
            forgeIds: [12],
            gerritIds: [34],
            bots: true,
        ));

        self::assertSame(
            ['bool' => ['filter' => [
                ['term' => ['source' => 'gerrit']],
                ['terms' => ['kind' => ['voted', 'merged']]],
                ['bool' => ['should' => [
                    ['terms' => ['author.forge_id' => [12]]],
                    ['terms' => ['author.gerrit_id' => [34]]],
                ], 'minimum_should_match' => 1]],
            ]]],
            $query,
        );
    }

    public function testTheBranchFiltersTheEventsButNotTheBranchesToPick(): void
    {
        $request = StreamQuery::requestWithBranches(new StreamFilter(branch: '13.4'), 0, 25);

        self::assertSame(['term' => ['branch' => '13.4']], $request['post_filter']);
        self::assertStringNotContainsString('13.4', (string) json_encode($request['query']));
        self::assertSame([['date' => 'desc'], ['id' => 'desc']], $request['sort']);
    }

    public function testACursorPagesAfterItsEventAndAllEventsCount(): void
    {
        $cursor = StreamCursor::fromValue('1763820000000-gerrit:91234:7');
        self::assertNotNull($cursor);

        $request = StreamQuery::request(new StreamFilter(), 0, 25, $cursor);

        self::assertSame([1763820000000, 'gerrit:91234:7'], $request['search_after']);
        self::assertArrayNotHasKey('from', $request);
        self::assertTrue($request['track_total_hits']);
        self::assertSame(50, StreamQuery::request(new StreamFilter(), 50, 25)['from']);
    }
}
