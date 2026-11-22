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

#[OA\Schema(description: 'How many events have a value: a channel, a kind or a branch.')]
final readonly class ActivityCount
{
    public function __construct(
        #[OA\Property(description: 'The value, as the filters take it.', example: 'gerrit')]
        public string $key,
        #[OA\Property(description: 'The value as a page names it.', example: 'Gerrit')]
        public string $label,
        #[OA\Property(description: 'How many events have it.', example: 4496)]
        public int $count,
    ) {
    }
}
