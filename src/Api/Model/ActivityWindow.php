<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use App\Api\ApiDate;
use App\Charts\TimeSeries;
use App\Enum\StreamIntervalEnum;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The time the activity covers, as the parameters chose it.')]
final readonly class ActivityWindow
{
    /**
     * @param list<string> $intervals
     */
    public function __construct(
        #[OA\Property(description: 'The start, included. ' . ApiDate::DESCRIPTION, example: '2026-11-14T23:00:00Z')]
        public string $from,
        #[OA\Property(description: 'The end, left out. ' . ApiDate::DESCRIPTION, example: ApiDate::EXAMPLE)]
        public string $until,
        #[OA\Property(description: 'Whether it ends now: new events still come into it.', example: true)]
        public bool $open,
        #[OA\Property(
            description: 'The days the window touches, a day just begun included. The changes of the key figures '
                . 'compare with as many days before, up to the same time of day.',
            example: 7,
        )]
        public int $days,
        #[OA\Property(
            description: 'The time zone the days and hours of the statistics count in.',
            example: 'Europe/Berlin',
        )]
        public string $timeZone,
        #[OA\Property(
            description: 'The length of a bucket of the statistics over time: the one of the parameter "interval", '
                . 'else one by the length of the window.',
            type: 'string',
            enum: StreamIntervalEnum::class,
        )]
        public string $interval,
        #[OA\Property(
            description: 'The intervals the parameter "interval" may pick for this window.',
            type: 'array',
            items: new OA\Items(
                type: 'string',
                enum: [TimeSeries::HOUR, TimeSeries::DAY, TimeSeries::WEEK, TimeSeries::MONTH],
            ),
        )]
        public array $intervals,
    ) {
    }
}
