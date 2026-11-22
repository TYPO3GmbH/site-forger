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

#[OA\Schema(description: 'What was closed over the years: this year and the two before, to compare them.')]
final readonly class ActivityClosedYears
{
    /**
     * @param list<ActivityClosedYear> $issues
     * @param list<ActivityClosedYear> $changes
     */
    public function __construct(
        #[OA\Property(
            description: 'The issues resolved, closed or rejected, by the day they first closed. The current year '
                . 'first.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityClosedYear::class)),
        )]
        public array $issues,
        #[OA\Property(
            description: 'The changes merged or abandoned, by the day they closed. The current year first.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityClosedYear::class)),
        )]
        public array $changes,
    ) {
    }
}
