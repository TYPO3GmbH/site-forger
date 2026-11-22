<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use App\Enum\StreamBetterEnum;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A number of the statistics with its course, like the people active.')]
final readonly class ActivityTrend
{
    /**
     * @param list<int> $values
     */
    public function __construct(
        #[OA\Property(description: 'The number in the time window.', example: 133)]
        public int $count,
        #[OA\Property(
            description: 'The number in the time before, as long as the window: to compare with.',
            example: 121,
        )]
        public int $previous,
        #[OA\Property(
            description: 'The number per bucket of time, in the order of "buckets": its sparkline.',
            type: 'array',
            items: new OA\Items(type: 'integer'),
        )]
        public array $values,
        #[OA\Property(
            description: 'Which way it gets better: "more" like the changes merged, "less" like the open issues or '
                . 'the waits, "neither" like the issues reported. The page colors the change by it.',
            type: 'string',
            enum: StreamBetterEnum::class,
        )]
        public string $better,
    ) {
    }
}
