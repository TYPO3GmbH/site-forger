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

#[OA\Schema(description: 'One page of the activity, the newest events first.')]
final readonly class ActivityPage
{
    /**
     * @param list<ActivityCount> $branches
     * @param list<ActivityEvent> $items
     */
    public function __construct(
        #[OA\Property(
            description: 'How many events the filter finds, at most 10000: count with the statistics beyond.',
            example: 1438,
        )]
        public int $total,
        #[OA\Property(description: 'The position of the first item of the page.', example: 0)]
        public int $offset,
        #[OA\Property(description: 'The size of the page.', example: 25)]
        public int $limit,
        #[OA\Property(
            description: 'The time the events are from.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityWindow::class))],
        )]
        public ActivityWindow $window,
        #[OA\Property(
            description: 'The person of the parameter "person", null without it.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityPerson::class))],
            nullable: true,
        )]
        public ?ActivityPerson $person,
        #[OA\Property(
            description: 'The branches of the events, the most events first, whichever branch the filter picked.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityCount::class)),
        )]
        public array $branches,
        #[OA\Property(
            description: 'The events of the page.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityEvent::class)),
        )]
        public array $items,
    ) {
    }
}
