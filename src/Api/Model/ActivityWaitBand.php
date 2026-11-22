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

#[OA\Schema(description: 'How many waits of the time window took as long as the band.')]
final readonly class ActivityWaitBand
{
    /**
     * @param list<ActivityCount> $kinds
     */
    public function __construct(
        #[OA\Property(
            description: 'How long the waits took.',
            type: 'string',
            enum: ['hour', 'day', 'week', 'month', 'longer'],
        )]
        public string $key,
        #[OA\Property(description: 'The band as a page names it.', example: '1 to 7 days')]
        public string $label,
        #[OA\Property(description: 'How many waits took as long.', example: 30)]
        public int $count,
        #[OA\Property(
            description: 'The waits of the band by the kind of event that ended them, like a change merged or '
                . 'abandoned. Empty for a wait that one kind of event ends.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityCount::class)),
        )]
        public array $kinds,
    ) {
    }
}
