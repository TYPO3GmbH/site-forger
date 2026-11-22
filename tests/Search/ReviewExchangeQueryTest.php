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
use App\Search\ReviewExchangeQuery;
use App\Search\StreamFilter;
use App\Search\StreamWindow;
use PHPUnit\Framework\TestCase;

final class ReviewExchangeQueryTest extends TestCase
{
    public function testTheReviewsGivenAreOnTheChangesOfOthersAndTheOnesGotByOthers(): void
    {
        $request = ReviewExchangeQuery::request(new StreamFilter(gerritIds: [7]), self::window());

        $filters = $request['query']['bool']['filter'];
        self::assertSame(['term' => ['source' => 'gerrit']], $filters[1]);
        self::assertSame(['terms' => ['kind' => ['voted', 'commented']]], $filters[2]);
        self::assertSame(['term' => ['bot' => false]], $filters[3]);

        $given = $request['aggs'][ReviewExchangeQuery::GIVEN];
        self::assertSame(self::person('author', 7), $given['filter']['bool']['filter'][0]);
        self::assertSame(self::person('owner', 7), $given['filter']['bool']['must_not'][0]);
        self::assertSame('owner.name', $given['aggs'][ReviewExchangeQuery::PARTNERS]['terms']['field']);

        $received = $request['aggs'][ReviewExchangeQuery::RECEIVED];
        self::assertSame(self::person('owner', 7), $received['filter']['bool']['filter'][0]);
        self::assertSame(self::person('author', 7), $received['filter']['bool']['must_not'][0]);
        self::assertSame('author.name', $received['aggs'][ReviewExchangeQuery::PARTNERS]['terms']['field']);
    }

    public function testTheWaitsAreTheOnesOfChangesOfThePersonBesideAll(): void
    {
        $request = ReviewExchangeQuery::waitRequest(new StreamFilter(gerritIds: [7]), self::window());

        $waits = $request['aggs'][ReviewExchangeQuery::WAITS];
        self::assertSame(['time-to-first-review', 'time-to-close'], array_keys($waits['filters']['filters']));
        self::assertSame(self::person('owner', 7), $waits['aggs'][ReviewExchangeQuery::OWN]['filter']);
    }

    private static function window(): StreamWindow
    {
        $now = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('UTC'));

        return StreamWindow::ofRange(StreamRangeEnum::MONTH, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private static function person(string $field, int $gerritId): array
    {
        return ['bool' => [
            'should' => [['terms' => [$field . '.gerrit_id' => [$gerritId]]]],
            'minimum_should_match' => 1,
        ]];
    }
}
