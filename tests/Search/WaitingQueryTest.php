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
use App\Search\WaitingQuery;
use PHPUnit\Framework\TestCase;

final class WaitingQueryTest extends TestCase
{
    public function testAChangeWaitsForItsOwnerAfterANegativeVoteAConflictOrComments(): void
    {
        $query = WaitingQuery::changes(WaitingForEnum::OWNER)['bool'];

        self::assertSame(WaitingQuery::ownerSteps(), $query['should']);
        self::assertSame(1, $query['minimum_should_match']);
        self::assertContains(['term' => ['work_in_progress' => true]], $query['must_not']);
    }

    public function testAChangeWaitsForAFirstReviewOnlyWhenNothingWaitsForItsOwner(): void
    {
        $query = WaitingQuery::changes(WaitingForEnum::FIRST_REVIEW)['bool'];

        self::assertContains(['range' => ['positive_reviews' => ['lt' => 1]]], $query['filter']);
        self::assertContains(['range' => ['negative_reviews' => ['lt' => 1]]], $query['filter']);
        self::assertContains(['term' => ['mergeable' => 'no']], $query['must_not']);
    }

    public function testAChangeWaitsForTheVoteOfAUserWhoNeitherOwnsNorVotedOnIt(): void
    {
        $mustNot = WaitingQuery::changes(WaitingForEnum::YOUR_VOTE, [9])['bool']['must_not'];

        self::assertContains(['terms' => ['owner_id' => [9]]], $mustNot);
        self::assertContains(['terms' => ['voters' => [9]]], $mustNot);
        // Without an account on Gerrit the user voted on no change
        self::assertCount(2, WaitingQuery::changes(WaitingForEnum::YOUR_VOTE)['bool']['must_not']);
    }

    public function testTheFilterOffersTheVoteOfTheUserOnlyWithAnAccountOnGerrit(): void
    {
        self::assertSame(
            ['Owner', 'First review'],
            array_keys(WaitingQuery::aggregation()['filters']['filters']),
        );
        self::assertSame(
            ['Owner', 'First review', 'Your vote'],
            array_keys(WaitingQuery::aggregation([9])['filters']['filters']),
        );
    }
}
