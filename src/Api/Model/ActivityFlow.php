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

#[OA\Schema(
    description: 'What came in and what went out of a channel per bucket: the issues or changes opened, also again, '
        . 'and those closed. The events of tools count. It does not depend on the kinds and the person.',
)]
final readonly class ActivityFlow
{
    /**
     * @param list<int> $incoming
     * @param list<int> $outgoing
     */
    public function __construct(
        #[OA\Property(description: 'The channel: "forge" for the issues, "gerrit" for the changes.', example: 'forge')]
        public string $source,
        #[OA\Property(
            description: 'Per bucket, in the order of "buckets": how many opened, the new ones and those opened again.',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            example: [41, 38],
        )]
        public array $incoming,
        #[OA\Property(
            description: 'Per bucket, in the order of "buckets": how many closed, resolved, rejected, merged or '
                . 'abandoned.',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            example: [52, 47],
        )]
        public array $outgoing,
    ) {
    }
}
