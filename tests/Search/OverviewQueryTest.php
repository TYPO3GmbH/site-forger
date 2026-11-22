<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\WaitingForEnum;
use App\Search\OverviewQuery;
use App\Search\WaitingQuery;
use PHPUnit\Framework\TestCase;

final class OverviewQueryTest extends TestCase
{
    public function testTheChangesToReviewWaitForTheVoteOfTheUser(): void
    {
        $request = OverviewQuery::reviewable([9], []);

        self::assertSame(
            [WaitingQuery::changes(WaitingForEnum::YOUR_VOTE, [9])],
            $request['query']['bool']['filter'],
        );
        self::assertSame(
            ['waiting_for' => ['Your_vote' => 'true']],
            OverviewQuery::reviewableFilters([]),
        );
    }

    public function testTheChangesTouchAnyPathOfTheInterests(): void
    {
        $filter = OverviewQuery::reviewable([9], ['*.rst', '/typo3/sysext/core/'], 5)['query']['bool']['filter'];

        self::assertSame(1, $filter[1]['bool']['minimum_should_match']);
        self::assertSame(['wildcard' => ['files' => ['value' => '*.rst']]], $filter[1]['bool']['should'][0]);
        self::assertSame(
            ['path' => '*.rst, /typo3/sysext/core/', 'match' => 'any'],
            OverviewQuery::reviewableFilters(['*.rst', '/typo3/sysext/core/'])['file'],
        );
    }

    public function testNewIssuesAreNewAndOfTheLastDaysAsTheSearchCountsThem(): void
    {
        $now = new \DateTimeImmutable('2026-11-22 00:30:00', new \DateTimeZone('Europe/Berlin'));
        $filter = OverviewQuery::newIssues(['Documentation'], $now)['query']['bool']['filter'];

        self::assertSame(['term' => ['status.id' => 1]], $filter[0]);
        self::assertSame('2026/11/07 00:00:00', $filter[1]['range']['created_on']['gte']);
        self::assertSame(['terms' => ['category.name' => ['Documentation']]], $filter[2]);
        self::assertSame(
            [
                'status' => ['New' => 'true'],
                'created' => ['from' => '2026-11-07'],
                'category' => ['Documentation' => 'true'],
            ],
            OverviewQuery::newIssuesFilters(['Documentation'], $now),
        );
    }

    public function testTheChangesOfPeopleWaitForTheStep(): void
    {
        $owners = ['terms' => ['owner_id' => [3]]];
        $filter = OverviewQuery::peopleChanges($owners, WaitingForEnum::FIRST_REVIEW)['query']['bool']['filter'];

        self::assertSame([$owners, WaitingQuery::changes(WaitingForEnum::FIRST_REVIEW)], $filter);
        self::assertSame(
            ['owner' => ['jdoe' => 'true', 'Jane_Doe' => 'true'], 'waiting_for' => ['First_review' => 'true']],
            OverviewQuery::peopleChangesFilters(['jdoe', 'Jane Doe'], WaitingForEnum::FIRST_REVIEW),
        );
    }

    public function testUnansweredIssuesAreStillNewAfterAWeek(): void
    {
        $now = new \DateTimeImmutable('2026-11-22 12:00:00', new \DateTimeZone('UTC'));
        $authors = ['terms' => ['author.id' => [7]]];
        $filter = OverviewQuery::unansweredIssues($authors, $now)['query']['bool']['filter'];

        self::assertSame($authors, $filter[0]);
        // The day of the filter counts in full: the issues of the 14th are 7 days old on the 22nd at most
        self::assertSame(['lt' => '2026/11/15 00:00:00'], $filter[2]['range']['created_on']);
        self::assertSame(
            ['author' => ['jdoe' => 'true'], 'status' => ['New' => 'true'], 'created' => ['to' => '2026-11-14']],
            OverviewQuery::unansweredIssuesFilters(['jdoe'], $now),
        );
    }
}
