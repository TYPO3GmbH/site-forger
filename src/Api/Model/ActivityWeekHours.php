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

#[OA\Schema(description: 'The activity per weekday and hour: when people are active.')]
final readonly class ActivityWeekHours
{
    /**
     * @param list<list<int>> $weekHours
     */
    public function __construct(
        #[OA\Property(
            description: 'The time the events are from.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityWindow::class))],
        )]
        public ActivityWindow $window,
        #[OA\Property(
            description: 'The events per weekday and hour in the time zone: seven lists, Monday first, of 24 hours.',
            type: 'array',
            items: new OA\Items(type: 'array', items: new OA\Items(type: 'integer')),
        )]
        public array $weekHours,
    ) {
    }
}
