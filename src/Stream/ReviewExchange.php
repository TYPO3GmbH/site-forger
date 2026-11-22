<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

/**
 * The reviews between a person and the others in a time window: the changes of others the person reviewed, the
 * changes of the person others reviewed, the closest partners of each, and how long the own changes waited. A
 * change needs several votes and comments: the trends count the changes, "givenReviews" and "receivedReviews" the
 * votes and comments. See ReviewExchangeQuery.
 */
final readonly class ReviewExchange
{
    /**
     * @param list<string>        $buckets         the keys of the buckets of time of the trends
     * @param int                 $givenReviews    the votes and comments of the person on the changes of others
     * @param int                 $receivedReviews the votes and comments of others on the changes of the person
     * @param StreamTrend         $given           the changes of others the person reviewed
     * @param StreamTrend         $received        the changes of the person others reviewed
     * @param list<ReviewPartner> $reviewed        whose changes the person reviewed, the most changes first
     * @param list<ReviewPartner> $reviewers       who reviewed the changes of the person, the most changes first
     * @param list<OwnWait>       $waits
     */
    public function __construct(
        public array $buckets,
        public int $givenReviews,
        public int $receivedReviews,
        public StreamTrend $given,
        public StreamTrend $received,
        public array $reviewed,
        public array $reviewers,
        public array $waits,
    ) {
    }
}
