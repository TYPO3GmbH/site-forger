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

#[OA\Schema(description: 'A message of the review log of a change: a vote, a patch set, a comment, a note of the CI.')]
final readonly class ReviewMessage
{
    public function __construct(
        #[OA\Property(
            description: 'The id of the message on Gerrit, the "messageId" of its comments.',
            example: 'cf7c66d8',
        )]
        public ?string $id,
        #[OA\Property(
            description: 'Who wrote it.',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: GerritAccount::class))],
        )]
        public ?GerritAccount $author,
        #[OA\Property(
            description: 'When it was written. ' . ApiDate::DESCRIPTION,
            format: 'date-time',
            example: ApiDate::EXAMPLE,
        )]
        public ?string $createdOn,
        #[OA\Property(description: 'The patch set it is about.', example: 2)]
        public ?int $patchSet,
        #[OA\Property(description: 'Whether the CI wrote it, not a person.', example: false)]
        public bool $bot,
        #[OA\Property(description: 'The text as Gerrit shows it.', example: 'Patch Set 2: Code-Review+1')]
        public string $text,
    ) {
    }
}
