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
use App\Enum\StreamMilestoneEnum;
use App\Enum\StreamNewcomerStateEnum;
use App\Enum\StreamSourceEnum;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'A first of somebody: their first issue on Forge, their first change in Gerrit, or the first change '
        . 'of theirs that was merged, and where it stands now.',
)]
final readonly class ActivityNewcomer
{
    public function __construct(
        #[OA\Property(
            description: 'Who reported the issue or pushed the change, for a merge the owner of the change.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityPerson::class))],
        )]
        public ActivityPerson $person,
        #[OA\Property(description: 'Which first it is.', type: 'string', enum: StreamMilestoneEnum::class)]
        public string $milestone,
        #[OA\Property(
            description: '"forge" for a first issue, "gerrit" for a first change.',
            type: 'string',
            enum: StreamSourceEnum::class,
        )]
        public string $source,
        #[OA\Property(description: 'The number of the issue or the change.', example: 97873)]
        public int $number,
        #[OA\Property(description: 'The subject of the issue or the change.', example: '[BUGFIX] Clear the cache')]
        public string $title,
        #[OA\Property(
            description: 'When it was reported, pushed or merged. ' . ApiDate::DESCRIPTION,
            example: ApiDate::EXAMPLE,
        )]
        public string $date,
        #[OA\Property(description: 'The branch of a change, null for an issue.', example: 'main')]
        public ?string $branch,
        #[OA\Property(
            description: 'Where the issue or the change stands now.',
            type: 'string',
            enum: StreamNewcomerStateEnum::class,
        )]
        public string $state,
        #[OA\Property(description: 'The state as a page names it.', example: 'Waiting for a first review')]
        public string $stateLabel,
        #[OA\Property(
            description: 'Whether a mentor could help now: it waits for an answer, a review or its author.',
            example: true,
        )]
        public bool $needsHelp,
        #[OA\Property(
            description: 'The issue on Forge or the change in Gerrit.',
            example: 'https://review.typo3.org/c/Packages/TYPO3.CMS/+/97873/',
        )]
        public string $url,
        #[OA\Property(
            description: 'The issue or the change in Forger.',
            example: 'https://forger.typo3.com/change/97873',
        )]
        public string $forgerUrl,
        #[OA\Property(
            description: 'Who merged the change of a first merge, null for the other firsts.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityPerson::class))],
            nullable: true,
        )]
        public ?ActivityPerson $mergedBy = null,
    ) {
    }
}
