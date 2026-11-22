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
use App\Enum\StreamKindEnum;
use App\Enum\StreamSourceEnum;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'Something that happened on Forge or in Gerrit: who did what on which issue or change.')]
final readonly class ActivityEvent
{
    /**
     * @param list<string> $summary
     */
    public function __construct(
        #[OA\Property(
            description: 'The id of the event: the source, the number and the note or message it comes from.',
            example: 'gerrit-91487-04bdf25439fa7cab11005cf686470e8f8bdc0b7e',
        )]
        public string $id,
        #[OA\Property(description: 'Where it happened.', type: 'string', enum: StreamSourceEnum::class)]
        public string $source,
        #[OA\Property(
            description: 'What happened. "created" is a new issue or the first patch set, "changed" a change of '
                . 'fields or of the state, "closed" an issue resolved, closed or rejected or a change abandoned.',
            type: 'string',
            enum: StreamKindEnum::class,
        )]
        public string $kind,
        #[OA\Property(description: 'The number of the issue on Forge or of the change on Gerrit.', example: 91487)]
        public int $number,
        #[OA\Property(description: 'The subject of the issue or the change.', example: '[BUGFIX] Clear the cache')]
        public string $title,
        #[OA\Property(description: 'When it happened. ' . ApiDate::DESCRIPTION, example: ApiDate::EXAMPLE)]
        public ?string $date,
        #[OA\Property(
            description: 'Who did it, null when Forge or Gerrit does not tell.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityPerson::class))],
            nullable: true,
        )]
        public ?ActivityPerson $author,
        #[OA\Property(description: 'Whether a tool wrote it, like the CI of Gerrit, not a person.', example: false)]
        public bool $bot,
        #[OA\Property(
            description: 'What changed in a few words, like "Status: New → Resolved" or "Code-Review +2". No text '
                . 'of a comment: that is on the page of the issue or the change.',
            type: 'array',
            items: new OA\Items(type: 'string'),
        )]
        public array $summary,
        #[OA\Property(description: 'The patch set of a change it is about, null without one.', example: 3)]
        public ?int $patchSet,
        #[OA\Property(description: 'The branch of the change, null for an issue.', example: 'main')]
        public ?string $branch,
        #[OA\Property(
            description: 'The issue on Forge or the change on Gerrit.',
            example: 'https://review.typo3.org/c/Packages/TYPO3.CMS/+/91487/',
        )]
        public string $url,
        #[OA\Property(
            description: 'The issue or the change on Forger.',
            example: 'https://forger.typo3.com/review/show/91487',
        )]
        public string $forgerUrl,
    ) {
    }
}
