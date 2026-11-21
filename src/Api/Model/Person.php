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

/**
 * A person on Forge: the author or the assignee of an issue.
 */
#[OA\Schema(description: 'A person on Forge.')]
final readonly class Person
{
    public function __construct(
        #[OA\Property(description: 'The number of the user on Forge.', example: 36415)]
        public ?int $id,
        #[OA\Property(description: 'The name as Forge shows it.', example: 'Jane Doe')]
        public string $name,
    ) {
    }
}
