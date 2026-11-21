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

#[OA\Schema(description: 'Why a request failed.')]
final readonly class Error
{
    public function __construct(
        #[OA\Property(
            description: 'A message for people.',
            example: 'The parameter "status" does not accept "bogus". Use one of: open, closed, all.',
        )]
        public string $error,
    ) {
    }
}
