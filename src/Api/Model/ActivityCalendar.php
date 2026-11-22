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
    description: 'The activity per day of a year, in its time zone: when people work in a year.',
)]
final readonly class ActivityCalendar
{
    /**
     * @param list<int> $counts
     */
    public function __construct(
        #[OA\Property(
            description: 'The year: the one of the parameter "year", the last 12 months without it.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityWindow::class))],
        )]
        public ActivityWindow $window,
        #[OA\Property(description: 'The first day of the year, as YYYY-MM-DD.', example: '2025-11-22')]
        public string $firstDay,
        #[OA\Property(
            description: 'The events per day, from the first day on, one for each day of the year.',
            type: 'array',
            items: new OA\Items(type: 'integer'),
        )]
        public array $counts,
    ) {
    }
}
