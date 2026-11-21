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

/**
 * A Forge issue in a list of the API. IssueDetail adds the fields of a single issue.
 */
#[OA\Schema(description: 'An issue in a list.')]
readonly class IssueSummary
{
    public function __construct(
        #[OA\Property(description: 'The number of the issue on Forge.', example: 105403)]
        public int $id,
        #[OA\Property(description: 'The subject.', example: 'Image crop is ignored in the backend preview')]
        public string $subject,
        #[OA\Property(description: 'The tracker, like "Bug" or "Feature".', example: 'Bug')]
        public ?string $tracker,
        #[OA\Property(
            description: 'The status as Forge names it, like "New", "Accepted" or "Under Review".',
            example: 'Under Review',
        )]
        public ?string $status,
        #[OA\Property(description: 'Whether the status counts as closed: Closed, Rejected or Resolved.')]
        public bool $closed,
        #[OA\Property(description: 'The priority, like "Should have".', example: 'Should have')]
        public ?string $priority,
        #[OA\Property(
            description: 'The area of the Core. Null when the issue has none, as many have.',
            example: 'Backend User Interface',
        )]
        public ?string $category,
        #[OA\Property(description: 'The target version on Forge.', example: '14.0')]
        public ?string $targetVersion,
        #[OA\Property(
            description: 'Who reported the issue.',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: Person::class))],
        )]
        public ?Person $author,
        #[OA\Property(
            description: 'Who the issue is assigned to.',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: Person::class))],
        )]
        public ?Person $assignedTo,
        #[OA\Property(
            description: 'When the issue was reported. ' . ApiDate::DESCRIPTION,
            format: 'date-time',
            example: ApiDate::EXAMPLE,
        )]
        public ?string $createdOn,
        #[OA\Property(
            description: 'When the issue changed last on Forge. ' . ApiDate::DESCRIPTION,
            format: 'date-time',
            example: ApiDate::EXAMPLE,
        )]
        public ?string $updatedOn,
        #[OA\Property(
            description: 'The issue on Forge.',
            format: 'uri',
            example: 'https://forge.typo3.org/issues/105403',
        )]
        public string $url,
        #[OA\Property(
            description: 'The issue in Forger.',
            format: 'uri',
            example: 'https://forger.typo3.com/issue/105403',
        )]
        public string $forgerUrl,
    ) {
    }
}
