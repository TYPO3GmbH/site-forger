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

#[OA\Schema(description: 'How many items of a list have a value.')]
final readonly class Bucket
{
    public function __construct(
        #[OA\Property(description: 'The value. "none" for the items without one.', example: 'Bug')]
        public string $name,
        #[OA\Property(description: 'How many items have it.', example: 412)]
        public int $count,
    ) {
    }
}
