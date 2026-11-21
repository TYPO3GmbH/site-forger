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
use App\Enum\ReviewStateEnum;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A Gerrit change in a list of the API. ChangeDetail adds the fields of a single change.
 */
#[OA\Schema(description: 'A change in a list.')]
readonly class ChangeSummary
{
    public const string STATE_DESCRIPTION = 'The state of the change. "ready" is open and ready for review, '
        . '"wip" is open and work in progress.';

    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[OA\Property(description: 'The number of the change on Gerrit.', example: 97865)]
        public int $number,
        #[OA\Property(
            description: 'The Change-Id. Backports of a change to other branches have the same one.',
            example: 'I13752ccd5cd00954877461c2622c0986c1fc8c6d',
        )]
        public ?string $changeId,
        #[OA\Property(description: 'The subject.', example: '[BUGFIX] Respect the image crop')]
        public string $subject,
        #[OA\Property(description: self::STATE_DESCRIPTION, enum: ReviewStateEnum::class, example: 'ready')]
        public string $state,
        #[OA\Property(description: 'The target branch. "main" for master.', example: 'main')]
        public ?string $branch,
        #[OA\Property(description: 'The topic on Gerrit, null for none.', example: null)]
        public ?string $topic,
        #[OA\Property(
            description: 'The tags in the subject, like "BUGFIX", "TASK", "FEATURE" or "!!!" for a breaking change.',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['BUGFIX'],
        )]
        public array $tags,
        #[OA\Property(
            description: 'Whether Gerrit can merge the change without a rebase, as it computed it last.',
            example: true,
        )]
        public ?bool $mergeable,
        #[OA\Property(description: 'The lines the change adds.', example: 21)]
        public ?int $insertions,
        #[OA\Property(description: 'The lines the change removes.', example: 6)]
        public ?int $deletions,
        #[OA\Property(description: 'The number of the current patch set.', example: 2)]
        public ?int $patchSets,
        #[OA\Property(
            description: 'The votes on the change.',
            allOf: [new OA\Schema(ref: new Model(type: Votes::class))],
        )]
        public Votes $votes,
        #[OA\Property(
            description: 'Who uploaded the change.',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: Owner::class))],
        )]
        public ?Owner $owner,
        #[OA\Property(
            description: 'When the change was uploaded. ' . ApiDate::DESCRIPTION,
            format: 'date-time',
            example: ApiDate::EXAMPLE,
        )]
        public ?string $createdOn,
        #[OA\Property(
            description: 'When the change changed last on Gerrit. ' . ApiDate::DESCRIPTION,
            format: 'date-time',
            example: ApiDate::EXAMPLE,
        )]
        public ?string $updatedOn,
        #[OA\Property(
            description: 'The change on Gerrit.',
            format: 'uri',
            example: 'https://review.typo3.org/c/Packages/TYPO3.CMS/+/97865/',
        )]
        public string $url,
        #[OA\Property(
            description: 'The change in Forger.',
            format: 'uri',
            example: 'https://forger.typo3.com/change/97865',
        )]
        public string $forgerUrl,
    ) {
    }
}
