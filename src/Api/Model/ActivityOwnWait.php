<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use App\Enum\StreamDurationEnum;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'How long the changes of a person waited, beside all changes in the same time window.')]
final readonly class ActivityOwnWait
{
    public function __construct(
        #[OA\Property(description: 'The kind of wait.', type: 'string', enum: StreamDurationEnum::class)]
        public string $key,
        #[OA\Property(description: 'The name of the wait.', example: 'Time to first review')]
        public string $label,
        #[OA\Property(
            description: 'What the wait measures.',
            example: 'From the first patch set to the first vote or comment of another person',
        )]
        public string $description,
        #[OA\Property(description: 'How many waits of changes of the person ended in the time window.', example: 6)]
        public int $count,
        #[OA\Property(
            description: 'The median of these waits in seconds. Null without one.',
            type: 'integer',
            example: 86400,
            nullable: true,
        )]
        public ?int $median,
        #[OA\Property(
            description: 'The median of the same wait of all changes in the time window, in seconds: to compare '
                . 'with. Null without one.',
            type: 'integer',
            example: 172800,
            nullable: true,
        )]
        public ?int $allMedian,
    ) {
    }
}
