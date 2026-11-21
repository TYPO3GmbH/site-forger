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

#[OA\Schema(description: 'A Gerrit change that belongs to an issue.')]
final readonly class IssueChange
{
    public const array RELATIONS = ['resolves', 'related', 'linked'];

    public function __construct(
        #[OA\Property(description: 'The number of the change on Gerrit.', example: 97865)]
        public int $number,
        #[OA\Property(description: 'The subject of the change.', example: '[BUGFIX] Respect the image crop')]
        public string $subject,
        #[OA\Property(description: ChangeSummary::STATE_DESCRIPTION, enum: ReviewStateEnum::class, example: 'ready')]
        public string $state,
        #[OA\Property(
            description: '"resolves" and "related" come from the footer of the commit message. "linked" means '
                . 'a comment of the issue links the change.',
            enum: self::RELATIONS,
            example: 'resolves',
        )]
        public string $relation,
        #[OA\Property(
            description: 'The change on Gerrit.',
            format: 'uri',
            example: 'https://review.typo3.org/c/Packages/TYPO3.CMS/+/97865/',
        )]
        public string $url,
    ) {
    }
}
