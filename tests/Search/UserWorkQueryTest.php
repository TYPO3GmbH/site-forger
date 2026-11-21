<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\IssueSortEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewSortEnum;
use App\Enum\ReviewStateEnum;
use App\Search\IssueSearch;
use App\Search\ReviewSearch;
use App\Search\ReviewSortQuery;
use App\Search\UserWorkQuery;
use PHPUnit\Framework\TestCase;

final class UserWorkQueryTest extends TestCase
{
    public function testIssuesMatchTheForgeUsersOrName(): void
    {
        $request = (new UserWorkQuery('Jane.Doe@Example.org', 'Jane Doe', forgeIds: [38237, 41]))->issues();

        self::assertSame(
            [
                'should' => [
                    ['terms' => ['author.id' => [38237, 41]]],
                    ['terms' => ['assigned_to.id' => [38237, 41]]],
                    ['terms' => ['author.name' => ['Jane Doe']]],
                    ['terms' => ['assigned_to.name.keyword' => ['Jane Doe']]],
                ],
                'minimum_should_match' => 1,
            ],
            $request['query']['bool']
        );
        self::assertSame([['updated_on' => 'desc']], $request['sort']);
        // Without Forge users the issues are found by the name alone, the issues keep no emails
        $byName = (new UserWorkQuery('jane@example.org', 'Jane Doe'))->issues()['query']['bool']['should'];
        self::assertSame([['terms' => ['author.name' => ['Jane Doe']]], ['terms' => ['assigned_to.name.keyword' => ['Jane Doe']]]], $byName);
    }

    public function testIssuesAndChangesEachInTheirGivenOrder(): void
    {
        $query = new UserWorkQuery('jane@example.org', 'Jane Doe', issueSort: IssueSortEnum::NEWEST, reviewSort: ReviewSortEnum::OLDEST);

        self::assertSame([['created_on' => 'desc']], $query->issues()['sort']);
        self::assertSame([['created_on' => 'asc']], $query->reviews()['sort']);
        // Without an order the recently updated first
        self::assertSame(
            ReviewSortQuery::sort(ReviewSortEnum::UPDATED),
            (new UserWorkQuery('jane@example.org', 'Jane Doe'))->reviews()['sort'],
        );
    }

    public function testClosedIssuesAreHiddenButCounted(): void
    {
        $request = (new UserWorkQuery('jane@example.org', 'Jane Doe'))->issues();

        self::assertSame(
            ['bool' => ['must_not' => [['terms' => ['status.id' => [5, 6, 3]]]]]],
            $request['post_filter']
        );
        self::assertSame(['terms' => ['field' => 'status.id', 'size' => 20]], $request['aggs']['status']);
    }

    public function testReviewsMatchOwnerAndHideMergedAndAbandoned(): void
    {
        $request = (new UserWorkQuery('jane@example.org', 'Jane Doe', gerritIds: [21249]))->reviews();

        self::assertSame(
            [
                'should' => [
                    ['terms' => ['owner_id' => [21249]]],
                    ['terms' => ['ownerEmail' => ['jane@example.org']]],
                    ['terms' => ['owner' => ['Jane Doe']]],
                ],
                'minimum_should_match' => 1,
            ],
            $request['query']['bool']
        );
        self::assertSame(
            ['bool' => ['must_not' => [['terms' => ['reviewstatus' => ['MERGED', 'ABANDONED']]]]]],
            $request['post_filter']
        );
    }

    public function testIncludeClosedWithoutSelectedStateHasNoPostFilter(): void
    {
        $query = new UserWorkQuery('jane@example.org', 'Jane Doe', true);

        self::assertArrayNotHasKey('post_filter', $query->issues());
        self::assertArrayNotHasKey('post_filter', $query->reviews());
    }

    public function testSelectedIssueStatusFiltersTheList(): void
    {
        $request = (new UserWorkQuery('jane@example.org', 'Jane Doe', true, issueStatus: IssueStatusEnum::IN_PROGRESS))->issues();

        self::assertSame(['bool' => ['filter' => [['term' => ['status.id' => 11]]]]], $request['post_filter']);
    }

    public function testReviewsCountEachStateAndFilterWorkInProgress(): void
    {
        $request = (new UserWorkQuery('jane@example.org', 'Jane Doe', true, reviewState: ReviewStateEnum::WORK_IN_PROGRESS))->reviews();

        self::assertSame(['ready', 'wip', 'merged', 'abandoned'], array_keys($request['aggs']['status']['filters']['filters']));
        self::assertSame(
            ['bool' => ['filter' => [['bool' => ['filter' => [['term' => ['reviewstatus' => 'NEW']], ['term' => ['work_in_progress' => true]]]]]]]],
            $request['post_filter']
        );
    }

    public function testOpenCountsLeaveOutTheClosedDocuments(): void
    {
        $query = new UserWorkQuery('jane@example.org', 'Jane Doe', true);

        self::assertSame(
            [['terms' => ['status.id' => [5, 6, 3]]]],
            $query->openIssuesCount()['query']['bool']['must_not']
        );
        self::assertSame($query->reviews()['query'], $query->openReviewsCount()['query']['bool']['must'][0]);
        self::assertSame(
            [['terms' => ['reviewstatus' => ['MERGED', 'ABANDONED']]]],
            $query->openReviewsCount()['query']['bool']['must_not']
        );
    }

    public function testSearchWordsNarrowDownTheWorkOfThePerson(): void
    {
        $query = new UserWorkQuery('jane@example.org', 'Jane Doe', searchTerms: 'cache');
        $reviews = $query->reviews()['query']['bool'];
        $issues = $query->issues()['query']['bool'];

        // The person as a filter, the words as the search has them
        self::assertSame((new UserWorkQuery('jane@example.org', 'Jane Doe'))->reviews()['query'], $reviews['filter'][0]);
        self::assertSame([ReviewSearch::query('cache')], $reviews['must']);
        self::assertSame([IssueSearch::query('cache', true)], $issues['must']);
        // Without words the person only
        self::assertArrayNotHasKey('filter', (new UserWorkQuery('jane@example.org', 'Jane Doe', searchTerms: ' '))->issues()['query']['bool']);
    }

    public function testEmptyIdentityMatchesNothing(): void
    {
        $request = (new UserWorkQuery(' ', ''))->issues();

        self::assertArrayNotHasKey('should', $request['query']['bool']);
        self::assertEquals([['match_all' => new \stdClass()]], $request['query']['bool']['must_not']);
    }
}
