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

#[OA\Schema(description: 'A partner in the reviews of a person: the changes reviewed between them.')]
final readonly class ActivityPartner
{
    public function __construct(
        #[OA\Property(
            description: 'The partner. Without an email.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityPerson::class))],
        )]
        public ActivityPerson $person,
        #[OA\Property(description: 'The changes reviewed between them in the time window.', example: 6)]
        public int $changes,
        #[OA\Property(description: 'The votes and comments on these changes.', example: 14)]
        public int $reviews,
    ) {
    }
}
