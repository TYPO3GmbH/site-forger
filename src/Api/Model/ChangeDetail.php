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
     * @param list<string>            $releases
     * @param list<ChangeFile>        $files
     * @param list<ChangeIssue>       $issues
     * @param list<ReviewMessage>     $messages
     * @param list<string>            $conflicts
     * @param list<ChainChange>       $chain
     * @param list<CodeComment>       $comments
     * @param list<ConflictingChange> $mergeConflicts
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
        #[OA\Property(
            description: 'The comments on the code and on the patch sets, the oldest first. Gerrit publishes them with '
                . 'a message of the review log, see "messageId". Empty for a change imported before Forger kept them.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: CodeComment::class)),
        )]
        public array $comments,
        #[OA\Property(
            description: 'The open changes Gerrit cannot merge together with this one, as its section "Merge '
                . 'conflicts". Gerrit tells them for an open change it can merge only.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ConflictingChange::class)),
        )]
        public array $mergeConflicts,
    ) {
        parent::__construct(...get_object_vars($summary));
    }
}
