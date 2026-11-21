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

#[OA\Schema(description: 'A comment on a change: on a line of a file, on a file, or on a patch set.')]
final readonly class CodeComment
{
    public function __construct(
        #[OA\Property(description: 'The id of the comment on Gerrit.', example: 'af2e78db_cdef7975')]
        public string $id,
        #[OA\Property(
            description: 'The message of the review log it was published with, its "id".',
            example: 'cf7c66d8da4848c1846caa90c8c7fc752344b681',
        )]
        public ?string $messageId,
        #[OA\Property(description: 'The comment it answers, null for the start of a thread.', example: null)]
        public ?string $inReplyTo,
        #[OA\Property(
            description: 'Who wrote it.',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: GerritAccount::class))],
        )]
        public ?GerritAccount $author,
        #[OA\Property(
            description: 'When it was published. ' . ApiDate::DESCRIPTION,
            format: 'date-time',
            example: ApiDate::EXAMPLE,
        )]
        public ?string $createdOn,
        #[OA\Property(description: 'The patch set it is on.', example: 1)]
        public ?int $patchSet,
        #[OA\Property(
            description: 'The path of the file, null for a comment on the patch set.',
            example: 'typo3/sysext/core/Classes/Cache/CacheManager.php',
        )]
        public ?string $file,
        #[OA\Property(description: 'The line, null for a comment on a file or a patch set.', example: 42)]
        public ?int $line,
        #[OA\Property(
            description: 'Whether it is the last comment of a thread that is not resolved: what the owner still has '
                . 'to answer.',
            example: false,
        )]
        public bool $unresolved,
        #[OA\Property(description: 'The text as Gerrit shows it.', example: 'Please add a test.')]
        public string $text,
    ) {
    }
}
