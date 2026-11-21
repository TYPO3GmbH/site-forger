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

#[OA\Schema(description: 'The votes on a change, without the votes of the CI.')]
final readonly class Votes
{
    private const string DESCRIPTION = 'The lowest negative vote if there is one, else the highest. '
        . 'Only mergers vote +2 and -2.';

    public function __construct(
        #[OA\Property(
            description: 'The vote of the label "Code-Review". ' . self::DESCRIPTION,
            enum: [-2, -1, 0, 1, 2],
            example: 1,
        )]
        public int $codeReview,
        #[OA\Property(
            description: 'The vote of the label "Verified". ' . self::DESCRIPTION,
            enum: [-2, -1, 0, 1, 2],
            example: 0,
        )]
        public int $verified,
    ) {
    }
}
