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

#[OA\Schema(description: 'The labels of a change people vote on.')]
final readonly class Votes
{
    public function __construct(
        #[OA\Property(
            description: 'The label "Code-Review": whether the code is good.',
            allOf: [new OA\Schema(ref: new Model(type: Label::class))],
        )]
        public Label $codeReview,
        #[OA\Property(
            description: 'The label "Verified": whether the change works, the CI votes on it, too.',
            allOf: [new OA\Schema(ref: new Model(type: Label::class))],
        )]
        public Label $verified,
    ) {
    }
}
