<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\UserWorkQuery;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStateEnum;
use PHPUnit\Framework\TestCase;

final class UserWorkQueryTest extends TestCase
{
    public function testIssuesMatchEmailOrName(): void
    {
        $request = (new UserWorkQuery('Jane.Doe@Example.org', 'Jane Doe'))->issues();

        self::assertSame(
            [
                'should' => [
                    ['terms' => ['author.email' => ['Jane.Doe@Example.org', 'jane.doe@example.org']]],
                    ['terms' => ['author.name' => ['Jane Doe']]],
                    ['terms' => ['assigned_to.name.keyword' => ['Jane Doe']]],
                ],
                'minimum_should_match' => 1,
            ],
            $request['query']['bool']
        );
        self::assertSame(['updated_on' => 'desc'], $request['sort']);
    }

    public function testClosedIssuesAreHiddenButCounted(): void
    {
        $request = (new UserWorkQuery('jane@example.org', 'Jane Doe'))->issues();

        self::assertSame(
            ['bool' => ['must_not' => [['terms' => ['status.name' => ['Closed', 'Rejected', 'Resolved']]]]]],
            $request['post_filter']
        );
        self::assertSame(['terms' => ['field' => 'status.name', 'size' => 20]], $request['aggs']['status']);
    }

    public function testReviewsMatchOwnerAndHideMergedAndAbandoned(): void
    {
        $request = (new UserWorkQuery('jane@example.org', 'Jane Doe'))->reviews();

        self::assertSame(
            [
                'should' => [
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

        self::assertSame(['bool' => ['filter' => [['term' => ['status.name' => 'In Progress']]]]], $request['post_filter']);
    }

    public function testReviewsCountEachStateAndConflictsAndFilterWorkInProgress(): void
    {
        $request = (new UserWorkQuery('jane@example.org', 'Jane Doe', true, reviewState: ReviewStateEnum::WORK_IN_PROGRESS))->reviews();

        self::assertSame(['ready', 'wip', 'merged', 'abandoned'], array_keys($request['aggs']['status']['filters']['filters']));
        self::assertArrayHasKey('conflicts', $request['aggs']);
        self::assertSame(
            ['bool' => ['filter' => [['bool' => ['filter' => [['term' => ['reviewstatus' => 'NEW']], ['term' => ['work_in_progress' => true]]]]]]]],
            $request['post_filter']
        );
    }

    public function testOpenCountsLeaveOutTheClosedDocuments(): void
    {
        $query = new UserWorkQuery('jane@example.org', 'Jane Doe', true);

        self::assertSame(
            [['terms' => ['status.name' => ['Closed', 'Rejected', 'Resolved']]]],
            $query->openIssuesCount()['query']['bool']['must_not']
        );
        self::assertSame($query->reviews()['query'], $query->openReviewsCount()['query']['bool']['must'][0]);
        self::assertSame(
            [['terms' => ['reviewstatus' => ['MERGED', 'ABANDONED']]]],
            $query->openReviewsCount()['query']['bool']['must_not']
        );
    }

    public function testEmptyIdentityMatchesNothing(): void
    {
        $request = (new UserWorkQuery(' ', ''))->issues();

        self::assertArrayNotHasKey('should', $request['query']['bool']);
        self::assertEquals([['match_all' => new \stdClass()]], $request['query']['bool']['must_not']);
    }
}
