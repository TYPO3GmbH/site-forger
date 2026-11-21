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

#[OA\Schema(description: 'A person on Gerrit: who uploaded a change, voted on it or wrote in its review log.')]
final readonly class GerritAccount
{
    public function __construct(
        #[OA\Property(description: 'The number of the account on Gerrit.', example: 14410)]
        public ?int $id,
        #[OA\Property(description: 'The name as Gerrit shows it.', example: 'Jane Doe')]
        public string $name,
        #[OA\Property(
            description: 'The username of the TYPO3 account. Null when it is an email address, as some people use.',
            example: 'jdoe',
        )]
        public ?string $username,
    ) {
    }
}
