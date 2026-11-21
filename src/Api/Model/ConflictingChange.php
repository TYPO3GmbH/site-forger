<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use App\Enum\ReviewStateEnum;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'An open change Gerrit cannot merge together with the change asked for.')]
final readonly class ConflictingChange
{
    public function __construct(
        #[OA\Property(description: 'The number of the change on Gerrit.', example: 95192)]
        public int $number,
        #[OA\Property(
            description: 'The subject, null when Forger does not have the change.',
            example: '[TASK] Rework database analyzer stack',
        )]
        public ?string $subject,
        #[OA\Property(
            description: 'The state, null when Forger does not have the change.',
            enum: ReviewStateEnum::class,
            example: 'ready',
        )]
        public ?string $state,
        #[OA\Property(
            description: 'The change on Gerrit.',
            format: 'uri',
            example: 'https://review.typo3.org/c/Packages/TYPO3.CMS/+/95192/',
        )]
        public string $url,
    ) {
    }
}
