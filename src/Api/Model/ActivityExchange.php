<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'The reviews between a person and the others in a time window: a review is a vote or a comment in '
        . 'Gerrit, not by a tool. A change needs several of them, so the balance counts the changes reviewed. And '
        . 'how long the changes of the person waited.',
)]
final readonly class ActivityExchange
{
    /**
     * @param list<string>          $buckets
     * @param list<ActivityPartner> $reviewed
     * @param list<ActivityPartner> $reviewers
     * @param list<ActivityOwnWait> $waits
     */
    public function __construct(
        #[OA\Property(
            description: 'The time window.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityWindow::class))],
        )]
        public ActivityWindow $window,
        #[OA\Property(
            description: 'The start of each bucket of time, for the values of "given" and "received".',
            type: 'array',
            items: new OA\Items(type: 'string'),
        )]
        public array $buckets,
        #[OA\Property(
            description: 'The changes of others the person reviewed.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityTrend::class))],
        )]
        public ActivityTrend $given,
        #[OA\Property(
            description: 'The changes of the person others reviewed.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityTrend::class))],
        )]
        public ActivityTrend $received,
        #[OA\Property(description: 'The votes and comments of the person on the changes of others.', example: 297)]
        public int $givenReviews,
        #[OA\Property(description: 'The votes and comments of others on the changes of the person.', example: 762)]
        public int $receivedReviews,
        #[OA\Property(
            description: 'Whose changes the person reviewed most, at most five, the most changes first.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityPartner::class)),
        )]
        public array $reviewed,
        #[OA\Property(
            description: 'Who reviewed the most changes of the person, at most five, the most changes first.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityPartner::class)),
        )]
        public array $reviewers,
        #[OA\Property(
            description: 'How long the changes of the person waited for the first review and until they were merged '
                . 'or abandoned.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityOwnWait::class)),
        )]
        public array $waits,
    ) {
    }
}
