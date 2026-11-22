<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Stream;

use App\Enum\StreamBetterEnum;
use App\Enum\StreamDurationEnum;
use App\Search\ReviewExchangeQuery;
use App\Search\StreamInsightsQuery;
use App\Stream\ReviewExchanges;
use PHPUnit\Framework\TestCase;

final class ReviewExchangesTest extends TestCase
{
    public function testItReadsTheReviewsThePartnersAndTheWaits(): void
    {
        $keys = ['2026-11-19', '2026-11-20'];
        $now = [
            ReviewExchangeQuery::GIVEN => [
                'doc_count' => 5,
                ReviewExchangeQuery::CHANGES => ['value' => 3],
                ReviewExchangeQuery::OVER_TIME => ['buckets' => [
                    ['key_as_string' => '2026-11-20', 'doc_count' => 5, ReviewExchangeQuery::CHANGES => ['value' => 3]],
                ]],
                ReviewExchangeQuery::PARTNERS => ['buckets' => [
                    self::partner('Jane Doe', 5, 3, ['owner' => ['name' => 'Jane Doe', 'gerrit_id' => 7]]),
                ]],
            ],
            ReviewExchangeQuery::RECEIVED => [
                'doc_count' => 4,
                ReviewExchangeQuery::CHANGES => ['value' => 2],
                ReviewExchangeQuery::PARTNERS => ['buckets' => [
                    self::partner('John Roe', 4, 2, ['author' => ['name' => 'John Roe', 'gerrit_id' => 8]]),
                ]],
            ],
        ];
        $before = [
            ReviewExchangeQuery::GIVEN => ['doc_count' => 2, ReviewExchangeQuery::CHANGES => ['value' => 1]],
            ReviewExchangeQuery::RECEIVED => null,
        ];
        $waits = ['buckets' => [
            'time-to-first-review' => [
                'doc_count' => 10,
                StreamInsightsQuery::MEDIAN => ['values' => [['value' => 7200.4]]],
                ReviewExchangeQuery::OWN => [
                    'doc_count' => 2,
                    StreamInsightsQuery::MEDIAN => ['values' => [['value' => 3600.0]]],
                ],
            ],
        ]];

        $exchange = ReviewExchanges::of($now, $before, $waits, $keys);

        self::assertSame($keys, $exchange->buckets);
        // A change needs several votes and comments: the balance counts the changes
        self::assertSame(
            [3, 1, [0, 3]],
            [$exchange->given->count, $exchange->given->previous, $exchange->given->values],
        );
        self::assertSame([5, 4], [$exchange->givenReviews, $exchange->receivedReviews]);
        self::assertSame([2, 0], [$exchange->received->count, $exchange->received->previous]);
        // More reviews given help the project, more reviews got are not good or bad by themselves
        self::assertSame(StreamBetterEnum::MORE, $exchange->given->better);
        self::assertSame(StreamBetterEnum::NEITHER, $exchange->received->better);
        self::assertSame('Jane Doe', $exchange->reviewed[0]->person->name);
        self::assertSame(7, $exchange->reviewed[0]->person->gerritId);
        self::assertSame([3, 5], [$exchange->reviewed[0]->changes, $exchange->reviewed[0]->reviews]);
        self::assertSame('John Roe', $exchange->reviewers[0]->person->name);
        self::assertSame(StreamDurationEnum::TIME_TO_FIRST_REVIEW, $exchange->waits[0]->duration);
        self::assertSame([2, 3600, 7200], [
            $exchange->waits[0]->count,
            $exchange->waits[0]->median,
            $exchange->waits[0]->allMedian,
        ]);
        // No change of the person ended a wait: no median to compare
        self::assertSame([0, null, null], [
            $exchange->waits[1]->count,
            $exchange->waits[1]->median,
            $exchange->waits[1]->allMedian,
        ]);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private static function partner(string $name, int $reviews, int $changes, array $source): array
    {
        return [
            'key' => $name,
            'doc_count' => $reviews,
            ReviewExchangeQuery::CHANGES => ['value' => $changes],
            ReviewExchangeQuery::LATEST => ['hits' => ['hits' => [['_source' => $source]]]],
        ];
    }
}
