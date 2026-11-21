<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use App\Api\ApiDate;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'One vote on a label, with who gave it.')]
final readonly class Vote
{
    public function __construct(
        #[OA\Property(
            description: 'Who voted.',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: GerritAccount::class))],
        )]
        public ?GerritAccount $account,
        #[OA\Property(description: 'The vote.', enum: Label::VOTES, example: 1)]
        public int $value,
        #[OA\Property(
            description: 'When it was given. ' . ApiDate::DESCRIPTION,
            format: 'date-time',
            example: ApiDate::EXAMPLE,
        )]
        public ?string $createdOn,
        #[OA\Property(description: 'Whether the CI gave it, not a person.', example: false)]
        public bool $bot,
    ) {
    }
}
