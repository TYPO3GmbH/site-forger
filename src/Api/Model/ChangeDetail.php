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
     * @param list<string>        $releases
     * @param list<ChangeFile>    $files
     * @param list<ChangeIssue>   $issues
     * @param list<ReviewMessage> $messages
     * @param list<string>        $conflicts
     * @param list<ChainChange>   $chain
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
            description: 'The files the current patch set touches, by path.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ChangeFile::class)),
        )]
        public array $files,
        #[OA\Property(
            description: 'The issues the footer names, those it resolves first.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ChangeIssue::class)),
        )]
        public array $issues,
        #[OA\Property(
            description: 'The review log, the oldest first: votes, new patch sets, comments and notes of the CI. '
                . 'Empty for a change imported before Forger kept it.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ReviewMessage::class)),
        )]
        public array $messages,
        #[OA\Property(
            description: 'The files with a conflict in the current patch set, as the review log names them.',
            type: 'array',
            items: new OA\Items(type: 'string'),
        )]
        public array $conflicts,
        #[OA\Property(description: 'The change this one is a cherry-pick of, a backport mostly.', example: null)]
        public ?int $cherryPickOf,
        #[OA\Property(
            description: 'The relation chain of an open change, the top first, the change itself among them. Empty '
                . 'without a chain, for a closed change and for one imported before Forger kept it.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ChainChange::class)),
        )]
        public array $chain,
    ) {
        parent::__construct(...get_object_vars($summary));
    }
}
