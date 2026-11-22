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

#[OA\Schema(description: 'The events of a channel, a kind or all of them per bucket of time.')]
final readonly class ActivitySeries
{
    /**
     * @param list<int> $values
     */
    public function __construct(
        #[OA\Property(
            description: 'What the series counts: "total", a channel like "gerrit", or a kind like "kind-voted". '
                . 'The colors of the page take it as their key.',
            example: 'gerrit',
        )]
        public string $key,
        #[OA\Property(description: 'The series as a page names it.', example: 'Gerrit')]
        public string $label,
        #[OA\Property(
            description: 'The events per bucket, in the order of "buckets".',
            type: 'array',
            items: new OA\Items(type: 'integer'),
        )]
        public array $values,
    ) {
    }
}
