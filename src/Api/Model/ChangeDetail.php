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

/**
 * A single Gerrit change: the fields of ChangeSummary, the commit message, the files and the issues.
 */
#[OA\Schema(description: 'A single change: the fields of ChangeSummary and more.')]
final readonly class ChangeDetail extends ChangeSummary
{
    /**
     * @param list<string>      $releases
     * @param list<string>      $files
     * @param list<ChangeIssue> $issues
     */
    public function __construct(
        ChangeSummary $summary,
        #[OA\Property(
            description: 'The commit message of the current patch set, with its footer.',
            example: "[BUGFIX] Respect the image crop\n\nResolves: #105403\nReleases: main, 13.4",
        )]
        public string $commitMessage,
        #[OA\Property(
            description: 'The branches of the footer line "Releases:".',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['main', '13.4'],
        )]
        public array $releases,
        #[OA\Property(
            description: 'The paths the current patch set changes.',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['typo3/sysext/backend/Classes/Preview/StandardContentPreviewRenderer.php'],
        )]
        public array $files,
        #[OA\Property(
            description: 'The issues the footer names, those it resolves first.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ChangeIssue::class)),
        )]
        public array $issues,
    ) {
        parent::__construct(...get_object_vars($summary));
    }
}
