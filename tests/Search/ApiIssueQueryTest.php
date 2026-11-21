<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Api\ListParameters;
use App\Enum\IssueSortEnum;
use App\Enum\IssueStateFilterEnum;
use App\Search\ApiIssueQuery;
use App\Search\IssueSearch;
use PHPUnit\Framework\TestCase;

final class ApiIssueQueryTest extends TestCase
{
    public function testListsTheOpenIssuesOfAPage(): void
    {
        $request = self::request(new ListParameters('', 10, 20), IssueStateFilterEnum::OPEN);

        self::assertSame([IssueSearch::closed()], $request['query']['bool']['must_not']);
        self::assertArrayNotHasKey('filter', $request['query']['bool']);
        self::assertSame(10, $request['size']);
        self::assertSame(20, $request['from']);
        self::assertTrue($request['track_total_hits']);
    }

    public function testListsTheClosedIssuesOnly(): void
    {
        $bool = self::request(new ListParameters(), IssueStateFilterEnum::CLOSED)['query']['bool'];

        self::assertArrayNotHasKey('must_not', $bool);
        self::assertSame([IssueSearch::closed()], $bool['filter']);
    }

    public function testListsAllIssues(): void
    {
        $bool = self::request(new ListParameters(), IssueStateFilterEnum::ALL)['query']['bool'];

        self::assertArrayNotHasKey('must_not', $bool);
        self::assertArrayNotHasKey('filter', $bool);
    }

    public function testFiltersByTheExactNamesOfTrackerAndCategory(): void
    {
        $request = ApiIssueQuery::request(
            new ListParameters('cache'),
            IssueStateFilterEnum::ALL,
            'Bug',
            'Caching',
            IssueSortEnum::RELEVANCE,
        );

        self::assertSame(
            [['term' => ['tracker.name' => 'Bug']], ['term' => ['category.name' => 'Caching']]],
            $request['query']['bool']['filter'],
        );
        self::assertSame([IssueSearch::query('cache', true)], $request['query']['bool']['must']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function request(ListParameters $list, IssueStateFilterEnum $state): array
    {
        return ApiIssueQuery::request($list, $state, null, null, IssueSortEnum::UPDATED);
    }
}
