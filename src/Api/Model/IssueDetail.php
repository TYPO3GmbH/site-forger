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
 * A single Forge issue: the fields of IssueSummary, the description, the relations, the changes, the comments and
 * the files.
 */
#[OA\Schema(description: 'A single issue: the fields of IssueSummary and more.')]
final readonly class IssueDetail extends IssueSummary
{
    /**
     * @param list<string>        $tags
     * @param list<int>           $subtasks
     * @param list<IssueRelation> $relations
     * @param list<int>           $duplicates
     * @param list<int>           $duplicatedBy
     * @param list<IssueChange>   $changes
     * @param list<Comment>       $comments
     * @param list<Attachment>    $attachments
     */
    public function __construct(
        IssueSummary $summary,
        #[OA\Property(
            description: 'The description in Textile markup, as Forge stores it.',
            example: 'The crop of an image is ignored in the preview of the page module.',
        )]
        public string $description,
        #[OA\Property(description: 'The TYPO3 version the report is about.', example: '13')]
        public ?string $typo3Version,
        #[OA\Property(description: 'The PHP version the report is about.', example: '8.3')]
        public ?string $phpVersion,
        #[OA\Property(
            description: 'The tags of the issue, in lower case. Tags that differ only in case are one.',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['image', 'crop'],
        )]
        public array $tags,
        #[OA\Property(
            description: 'The issue this one is a subtask of. Null also when Forger does not have it.',
            example: null,
        )]
        public ?int $parent,
        #[OA\Property(
            description: 'The subtasks of the issue, the lowest numbers first.',
            type: 'array',
            items: new OA\Items(type: 'integer'),
        )]
        public array $subtasks,
        #[OA\Property(
            description: 'The other relations of Forge, no duplicates, to the issues Forger has.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: IssueRelation::class)),
        )]
        public array $relations,
        #[OA\Property(
            description: 'The issues this one duplicates, as Forge relates them, those Forger has.',
            type: 'array',
            items: new OA\Items(type: 'integer'),
        )]
        public array $duplicates,
        #[OA\Property(
            description: 'The issues that duplicate this one, those Forger has.',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            example: [101234],
        )]
        public array $duplicatedBy,
        #[OA\Property(
            description: 'The changes that belong to the issue, the ones updated last first. At most 50.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: IssueChange::class)),
        )]
        public array $changes,
        #[OA\Property(
            description: 'The comments, the oldest first. Documents of older imports may have those of issues under '
                . 'review only, until the import loads them again.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: Comment::class)),
        )]
        public array $comments,
        #[OA\Property(
            description: 'The files, the oldest first. A report about how something looks often has its evidence '
                . 'here, as screenshots. Empty for documents of older imports, until the import loads them again.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: Attachment::class)),
        )]
        public array $attachments,
    ) {
        parent::__construct(...get_object_vars($summary));
    }
}
