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
use App\Enum\StreamDurationEnum;
use App\Enum\StreamSourceEnum;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'How long work waited: the median time until an issue was resolved, a change reviewed or merged.',
)]
final readonly class ActivityDuration
{
    /**
     * @param list<ActivityWaitBand> $bands
     */
    public function __construct(
        #[OA\Property(description: 'What waited.', type: 'string', enum: StreamDurationEnum::class)]
        public string $key,
        #[OA\Property(description: 'The wait as a page names it.', example: 'Time to close')]
        public string $label,
        #[OA\Property(
            description: 'From when to when it counts.',
            example: 'From the first patch set until the change was merged or abandoned',
        )]
        public string $description,
        #[OA\Property(description: 'How many waits ended in the time window.', example: 704)]
        public int $count,
        #[OA\Property(
            description: 'The median in seconds of the waits that ended in the time window, null without one.',
            example: 432000,
        )]
        public ?int $median,
        #[OA\Property(
            description: 'The median in seconds in the time before, as long as the window: to compare with.',
            example: 518400,
        )]
        public ?int $previous,
        #[OA\Property(
            description: 'How many waits of the time window took how long, the shortest band first. A long wait is '
                . 'one of many here: it does not distort the picture like in a chart over time.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityWaitBand::class)),
        )]
        public array $bands,
        #[OA\Property(
            description: 'The channel of the issues or changes.',
            type: 'string',
            enum: StreamSourceEnum::class,
        )]
        public string $source,
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
