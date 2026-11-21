<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'An open issue that may describe the same as the issue asked for.')]
final readonly class Duplicate
{
    public const array RELATIONS = ['duplicates', 'duplicatedBy'];

    public function __construct(
        #[OA\Property(
            description: 'The issue.',
            allOf: [new OA\Schema(ref: new Model(type: IssueSummary::class))],
        )]
        public IssueSummary $issue,
        #[OA\Property(
            description: 'How much wording the two issues share, in percent, the mean of both directions. 100 would '
                . 'be the same wording. It is no probability. Null for an issue Forge relates as a duplicate that '
                . 'the search did not find.',
            minimum: 0,
            maximum: 100,
            example: 64,
        )]
        public ?int $similarity,
        #[OA\Property(
            description: 'How Forge already relates the two: "duplicates" when the issue asked for duplicates this '
                . 'one, "duplicatedBy" when this one duplicates it. Null for an issue only the search found.',
            enum: self::RELATIONS,
            example: null,
        )]
        public ?string $relation,
    ) {
    }
}
