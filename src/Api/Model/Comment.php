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

#[OA\Schema(description: 'A comment on an issue, as Forge shows it. Private comments are not in Forger.')]
final readonly class Comment
{
    /**
     * The accounts on Forge that write notes for a tool, not as a person.
     */
    public const array BOTS = ['Gerrit Code Review', 'Mr. Hudson'];

    public function __construct(
        #[OA\Property(
            description: 'Who wrote it.',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: Person::class))],
        )]
        public ?Person $author,
        #[OA\Property(
            description: 'When it was written. ' . ApiDate::DESCRIPTION,
            format: 'date-time',
            example: ApiDate::EXAMPLE,
        )]
        public ?string $createdOn,
        #[OA\Property(
            description: 'Whether a tool wrote it, like Gerrit when a patch set is pushed.',
            example: false,
        )]
        public bool $bot,
        #[OA\Property(
            description: 'The text in Textile markup, as Forge stores it.',
            example: 'Waiting for the feedback of the accessibility team.',
        )]
        public string $text,
    ) {
    }
}
