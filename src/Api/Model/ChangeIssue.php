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

#[OA\Schema(description: 'A Forge issue the footer of the commit message names.')]
final readonly class ChangeIssue
{
    public const array RELATIONS = ['resolves', 'related'];

    public function __construct(
        #[OA\Property(description: 'The number of the issue on Forge.', example: 105403)]
        public int $id,
        #[OA\Property(description: 'The footer line that names the issue.', enum: self::RELATIONS, example: 'resolves')]
        public string $relation,
        #[OA\Property(
            description: 'The subject of the issue. Null when Forger does not have the issue: it is private or in '
                . 'another project.',
            example: 'Image crop is ignored in the backend preview',
        )]
        public ?string $subject,
        #[OA\Property(description: 'The status of the issue on Forge.', example: 'Under Review')]
        public ?string $status,
        #[OA\Property(
            description: 'The issue on Forge.',
            format: 'uri',
            example: 'https://forge.typo3.org/issues/105403',
        )]
        public string $url,
    ) {
    }
}
