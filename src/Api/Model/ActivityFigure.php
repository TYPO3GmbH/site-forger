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
use App\Enum\StreamFigureEnum;
use App\Enum\StreamSourceEnum;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A key figure of the activity: what the people got done, like the issues closed.')]
final readonly class ActivityFigure
{
    /**
     * @param list<string> $kinds
     * @param list<int>    $values
     */
    public function __construct(
        #[OA\Property(description: 'What it counts.', type: 'string', enum: StreamFigureEnum::class)]
        public string $key,
        #[OA\Property(description: 'The figure as a page names it.', example: 'Issues closed')]
        public string $label,
        #[OA\Property(description: 'What it counts in a few words.', example: 'Resolved, closed or rejected')]
        public string $description,
        #[OA\Property(description: 'How many events it counts in the time window.', example: 42)]
        public int $count,
        #[OA\Property(
            description: 'How many in the time before, as long as the window: to compare with. For a window '
                . 'that ends now, as long as has passed of it.',
            example: 37,
        )]
        public int $previous,
        #[OA\Property(
            description: 'How many per bucket of time, in the order of "buckets" of the statistics: its sparkline.',
            type: 'array',
            items: new OA\Items(type: 'integer'),
        )]
        public array $values,
        #[OA\Property(
            description: 'The channel of its events, for the parameter "source".',
            type: 'string',
            enum: StreamSourceEnum::class,
        )]
        public string $source,
        #[OA\Property(
            description: 'The kinds of its events, any of them, for the parameter "kind": values of "kind" of an '
                . 'event.',
            type: 'array',
            items: new OA\Items(type: 'string'),
        )]
        public array $kinds,
        #[OA\Property(
            description: 'Which way it gets better: "more" like the changes merged, "less" like the open issues or '
                . 'the waits, "neither" like the issues reported. The page colors the change by it.',
            type: 'string',
            enum: StreamBetterEnum::class,
        )]
        public string $better,
        #[OA\Property(
            description: '1 when its events open issues or changes, -1 when they close them, else 0: what comes in '
                . 'and what goes out.',
            example: -1,
        )]
        public int $openDelta,
    ) {
    }
}
