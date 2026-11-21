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

#[OA\Schema(description: 'A change of the relation chain of a change.')]
final readonly class ChainChange
{
    public const array PLACES = ['above', 'this', 'below'];

    public function __construct(
        #[OA\Property(description: 'The number of the change on Gerrit.', example: 97794)]
        public int $number,
        #[OA\Property(description: 'The subject.', example: '[TASK] Debug bar for the record form')]
        public string $subject,
        #[OA\Property(
            description: 'Where it is in the chain: "above" is based on the change asked for, "below" is what it is '
                . 'based on, "this" is the change itself.',
            enum: self::PLACES,
            example: 'above',
        )]
        public string $place,
        #[OA\Property(
            description: 'The state, null when Forger does not have the change.',
            enum: ReviewStateEnum::class,
            example: 'ready',
        )]
        public ?string $state,
        #[OA\Property(description: 'The patch set of the change in the chain.', example: 3)]
        public int $patchSet,
        #[OA\Property(description: 'The current patch set of the change.', example: 3)]
        public int $currentPatchSet,
        #[OA\Property(
            description: 'Whether the chain has an older patch set than the current one: what is above needs a rebase.',
            example: false,
        )]
        public bool $outdated,
        #[OA\Property(
            description: 'The change on Gerrit.',
            format: 'uri',
            example: 'https://review.typo3.org/c/Packages/TYPO3.CMS/+/97794/',
        )]
        public string $url,
    ) {
    }
}
