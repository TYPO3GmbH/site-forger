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
use App\Enum\StreamCrewEnum;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'The people who carry the review and how widely they spread the work. The carriers are the fewest '
        . 'people who together did half of it: the more, the better.',
)]
final readonly class ActivityCrew
{
    /**
     * @param list<float> $carrierShares
     */
    public function __construct(
        #[OA\Property(description: 'Which work it counts.', type: 'string', enum: StreamCrewEnum::class)]
        public string $key,
        #[OA\Property(description: 'The crew as a page names it.', example: 'Merges')]
        public string $label,
        #[OA\Property(description: 'Which work it counts in a few words.', example: 'Who merged changes in Gerrit')]
        public string $description,
        #[OA\Property(description: 'How many people did the work in the time window, counted by name.', example: 16)]
        public int $people,
        #[OA\Property(
            description: 'The fewest people who together did half of the work in the time window, 0 without any.',
            example: 3,
        )]
        public int $carriers,
        #[OA\Property(description: 'The carriers in the time before, as long as the window.', example: 2)]
        public int $previousCarriers,
        #[OA\Property(
            description: 'The percent of the work each carrier did, the busiest first.',
            type: 'array',
            items: new OA\Items(type: 'number', format: 'float'),
            example: [24.1, 15.3, 11.2],
        )]
        public array $carrierShares,
        #[OA\Property(
            description: 'Which way it gets better: "more" carriers spread the work wider.',
            type: 'string',
            enum: StreamBetterEnum::class,
        )]
        public string $better,
    ) {
    }
}
