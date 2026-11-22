<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The issues or changes closed in a year, summed up month by month.')]
final readonly class ActivityClosedYear
{
    /**
     * @param list<int|null> $closed
     */
    public function __construct(
        #[OA\Property(description: 'The year.', example: 2026)]
        public int $year,
        #[OA\Property(
            description: 'How many closed in the year up to the end of each month, twelve numbers, January first. '
                . 'Null for the months to come.',
            type: 'array',
            items: new OA\Items(type: 'integer', nullable: true),
            example: [310, 598, 921, 1204, 1377, 1502, 1688, 1840, 2215, 3102, 3640, null],
        )]
        public array $closed,
    ) {
    }
}
