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
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'The firsts of new people in the time window: first issues, first changes, first merges. Each first '
        . 'counts: somebody new on Forge and in Gerrit counts twice as a contribution.',
)]
final readonly class ActivityNewcomers
{
    /**
     * @param list<ActivityCount>    $milestones
     * @param list<ActivityNewcomer> $people
     */
    public function __construct(
        #[OA\Property(
            description: 'The time of the first changes.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityWindow::class))],
        )]
        public ActivityWindow $window,
        #[OA\Property(
            description: 'How many new contributions the time window has: first issues and first changes.',
            example: 12,
        )]
        public int $count,
        #[OA\Property(description: 'How many in the time before, as long as the window.', example: 9)]
        public int $previous,
        #[OA\Property(
            description: 'Which way it gets better: "more" new people come.',
            type: 'string',
            enum: StreamBetterEnum::class,
        )]
        public string $better,
        #[OA\Property(
            description: 'How many of each first: first issues, first changes and first merges.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityCount::class)),
        )]
        public array $milestones,
        #[OA\Property(
            description: 'The newest of them, at most 20, the newest first.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityNewcomer::class)),
        )]
        public array $people,
    ) {
    }
}
