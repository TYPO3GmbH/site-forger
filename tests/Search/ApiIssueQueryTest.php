<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Api\IssueFilters;
use App\Api\ListParameters;
use App\Api\PersonFilter;
use App\Enum\IssueSortEnum;
use App\Enum\IssueStateFilterEnum;
use App\Report\CategoryOverview;
use App\Search\ApiIssueQuery;
use App\Search\IssueSearch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiIssueQueryTest extends TestCase
{
    public function testListsTheOpenIssuesOfAPage(): void
    {
        $request = self::request(new IssueFilters(), new ListParameters('', 10, 20));

        self::assertSame([IssueSearch::closed()], $request['query']['bool']['must_not']);
        self::assertArrayNotHasKey('filter', $request['query']['bool']);
        self::assertArrayNotHasKey('aggregations', $request);
        self::assertSame(10, $request['size']);
        self::assertSame(20, $request['from']);
        self::assertTrue($request['track_total_hits']);
    }

    public function testListsTheClosedIssuesOnly(): void
    {
        $bool = self::request(new IssueFilters(IssueStateFilterEnum::CLOSED))['query']['bool'];

        self::assertArrayNotHasKey('must_not', $bool);
        self::assertSame([IssueSearch::closed()], $bool['filter']);
    }

    public function testListsAllIssues(): void
    {
        $bool = self::request(new IssueFilters(IssueStateFilterEnum::ALL))['query']['bool'];

        self::assertArrayNotHasKey('must_not', $bool);
        self::assertArrayNotHasKey('filter', $bool);
    }

    public function testFiltersByTheExactNamesOfTrackerAndCategory(): void
    {
        $filters = new IssueFilters(IssueStateFilterEnum::ALL, tracker: 'Bug', category: 'Caching');

        self::assertSame(
            [['term' => ['tracker.name' => 'Bug']], ['term' => ['category.name' => 'Caching']]],
            self::request($filters, new ListParameters('cache'))['query']['bool']['filter'],
        );
    }

    public function testFiltersByATagInAnyCase(): void
    {
        $filters = new IssueFilters(IssueStateFilterEnum::ALL, tag: 'Accessibility');

        self::assertSame(
            [['term' => ['tags' => 'accessibility']]],
            self::request($filters)['query']['bool']['filter'],
        );
        self::assertSame('a11y', IssueFilters::fromRequest(new Request(['tag' => ' a11y ']))->tag);
    }

    public function testFindsTheIssuesWithoutACategory(): void
    {
        $filters = new IssueFilters(IssueStateFilterEnum::ALL, category: ApiIssueQuery::NONE);

        self::assertSame(
            [CategoryOverview::withoutCategoryQuery()],
            self::request($filters)['query']['bool']['filter'],
        );
    }

    public function testFiltersByThePeopleOnForge(): void
    {
        $filters = new IssueFilters(
            IssueStateFilterEnum::ALL,
            author: PersonFilter::number(1),
            assignee: PersonFilter::name(['Nägler']),
            involving: PersonFilter::number(3),
        );

        self::assertSame(
            [
                ['term' => ['author.id' => 1]],
                ['bool' => ['must' => [
                    ['wildcard' => ['assigned_to.name.folded' => ['value' => '*nagler*']]],
                ]]],
                ['bool' => [
                    'should' => [['term' => ['author.id' => 3]], ['term' => ['assigned_to.id' => 3]]],
                    'minimum_should_match' => 1,
                ]],
            ],
            self::request($filters)['query']['bool']['filter'],
        );
    }

    public function testFiltersByWholeDaysSinceAndBefore(): void
    {
        $day = static fn (string $day): \DateTimeImmutable => new \DateTimeImmutable($day . ' 00:00:00 UTC');
        $filters = new IssueFilters(
            IssueStateFilterEnum::ALL,
            createdSince: $day('2026-01-01'),
            createdBefore: $day('2026-07-01'),
            updatedBefore: $day('2020-01-01'),
        );

        self::assertSame(
            [
                ['range' => ['created_on' => [
                    'gte' => '2026/01/01 00:00:00',
                    'lt' => '2026/07/01 00:00:00',
                    'format' => 'yyyy/MM/dd HH:mm:ss',
                ]]],
                ['range' => ['updated_on' => ['lt' => '2020/01/01 00:00:00', 'format' => 'yyyy/MM/dd HH:mm:ss']]],
            ],
            self::request($filters)['query']['bool']['filter'],
        );
    }

    public function testOnlyAClosedIssueCountsByTheDayItClosed(): void
    {
        $filters = new IssueFilters(
            IssueStateFilterEnum::ALL,
            closedSince: new \DateTimeImmutable('2026-05-25 00:00:00 UTC'),
        );

        self::assertSame(
            [
                ['range' => ['closed_on' => ['gte' => '2026/05/25 00:00:00', 'format' => 'yyyy/MM/dd HH:mm:ss']]],
                IssueSearch::closed(),
            ],
            self::request($filters)['query']['bool']['filter'],
        );
    }

    public function testAClosedDayListsTheClosedIssuesWithoutAStatus(): void
    {
        $filters = IssueFilters::fromRequest(new Request(['closedBefore' => '2026-05-25']));

        self::assertSame(IssueStateFilterEnum::CLOSED, $filters->state);
        self::assertSame(IssueStateFilterEnum::OPEN, IssueFilters::fromRequest(new Request())->state);
    }

    public function testCountsHowTheIssuesSpreadOnlyWhenAsked(): void
    {
        $request = ApiIssueQuery::request(new ListParameters(), new IssueFilters(), IssueSortEnum::UPDATED, true);

        self::assertSame(
            ['status', 'tracker', 'category', 'tag', 'createdYear', 'updatedYear'],
            array_keys($request['aggregations']),
        );
        self::assertSame(ApiIssueQuery::NONE, $request['aggregations']['category']['terms']['missing']);
        self::assertSame(1, $request['aggregations']['createdYear']['date_histogram']['min_doc_count']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function request(IssueFilters $filters, ListParameters $list = new ListParameters()): array
    {
        return ApiIssueQuery::request($list, $filters, IssueSortEnum::UPDATED);
    }
}
