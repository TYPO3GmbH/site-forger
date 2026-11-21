<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api;

use App\Api\Model\IssueChange;
use App\Api\Model\IssueDetail;
use App\Api\Model\IssueRelation;
use App\Api\Model\IssueSummary;
use App\Api\Model\Person;
use App\Document\Issue;
use App\Document\IssueRelation as ForgeRelation;
use App\Document\RelatedChange;
use App\People\Person as IndexedPerson;
use App\Twig\LinkTwigExtension;
use Doctrine\Common\Collections\ReadableCollection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns the issues of the index into the objects of the API. Email addresses never leave Forger: they are kept for
 * the avatars only.
 */
final readonly class IssueMapper
{
    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function summary(Issue $issue): IssueSummary
    {
        return new IssueSummary(
            id: $issue->id,
            subject: $issue->subject,
            tracker: self::name($issue->tracker),
            status: self::name($issue->status),
            closed: true === $issue->statusEnum()?->isClosed(),
            priority: self::name($issue->priority),
            category: self::name($issue->category),
            targetVersion: self::name($issue->fixedVersion),
            author: self::person($issue->author),
            assignedTo: self::person($issue->assignedTo),
            createdOn: ApiDate::fromIndex($issue->createdOn),
            updatedOn: ApiDate::fromIndex($issue->updatedOn),
            url: sprintf(LinkTwigExtension::FORGE_ISSUE, $issue->id),
            forgerUrl: $this->urls->generate(
                'issue_show',
                ['id' => $issue->id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        );
    }

    /**
     * @param ReadableCollection<int, RelatedChange> $changes  see Relations::changesOf()
     * @param ReadableCollection<int, Issue>         $subtasks see Relations::subtasksOf()
     * @param array<int, true>                       $indexed  the related issues Forger has, see
     *                                                         Relations::indexedRelatedIssues(); the others may
     *                                                         be private and are left out
     */
    public function detail(
        Issue $issue,
        ReadableCollection $changes,
        ReadableCollection $subtasks,
        array $indexed,
    ): IssueDetail {
        $public = static fn (int $number): bool => isset($indexed[$number]);

        return new IssueDetail(
            summary: $this->summary($issue),
            description: $issue->description,
            typo3Version: $issue->typo3Version,
            phpVersion: $issue->phpVersion,
            tags: $issue->tags,
            parent: null !== $issue->parent && $public($issue->parent) ? $issue->parent : null,
            subtasks: array_values($subtasks->map(static fn (Issue $subtask): int => $subtask->id)->toArray()),
            relations: array_values(array_map(
                static fn (ForgeRelation $relation): IssueRelation => new IssueRelation(
                    $relation->type->value,
                    $relation->issue,
                ),
                array_filter($issue->relations, static fn (ForgeRelation $relation): bool => $public($relation->issue)),
            )),
            duplicates: array_values(array_filter($issue->duplicates, $public)),
            duplicatedBy: array_values(array_filter($issue->duplicatedBy, $public)),
            changes: array_values($changes->map(static fn (RelatedChange $related): IssueChange => new IssueChange(
                number: $related->change->id,
                subject: $related->change->subject,
                state: $related->change->state()->value,
                relation: strtolower($related->relation),
                url: sprintf(LinkTwigExtension::GERRIT_CHANGE, $related->change->id),
            ))->toArray()),
        );
    }

    /**
     * @param array{id?: int, name?: string}|null $reference a reference of Forge, like the status
     */
    private static function name(?array $reference): ?string
    {
        return isset($reference['name']) ? (string) $reference['name'] : null;
    }

    private static function person(?IndexedPerson $person): ?Person
    {
        return null === $person ? null : new Person($person->forgeId, $person->name);
    }
}
