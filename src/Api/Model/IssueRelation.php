<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use App\Enum\IssueRelationEnum;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A relation of Forge to another issue, other than a duplicate.')]
final readonly class IssueRelation
{
    public function __construct(
        #[OA\Property(
            description: 'How the issue relates to the other one, seen from this issue.',
            enum: IssueRelationEnum::class,
            example: 'relates',
        )]
        public string $type,
        #[OA\Property(description: 'The number of the other issue.', example: 104990)]
        public int $issue,
    ) {
    }
}
