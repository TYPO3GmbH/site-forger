<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Enum\IssueRelationEnum;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * How issues and changes belong together, for the pages of an issue and of a change.
 */
final readonly class Relations
{
    /**
     * The page of an issue shows this many changes at most, the ones updated last first.
     */
    private const int CHANGES = 50;

    public const string LINKED = 'Linked';

    public const string PARENT = 'Parent task';

    public function __construct(
        private ChangeRepository $changes,
        private IssueRepository $issues,
    ) {
    }

    /**
     * The changes whose footer resolves or names the issue, and those its comments link.
     *
     * @return ReadableCollection<int, RelatedChange> the ones updated last first
     */
    public function changesOf(Issue $issue): ReadableCollection
    {
        $id = $issue->id;
        $numbers = $issue->changeNumbers();
        $found = $this->changes->search([
            'query' => ['bool' => ['should' => [
                ['term' => ['resolves' => $id]],
                ['term' => ['related' => $id]],
                ['terms' => ['id' => [] === $numbers ? [0] : $numbers]],
            ]]],
            'sort' => [['updated_on' => 'desc']],
            'size' => self::CHANGES,
        ]);

        return $found->map(static fn (Change $change): RelatedChange => new RelatedChange($change, match (true) {
            in_array($id, $change->resolves, true) => CommitMessage::RESOLVES,
            in_array($id, $change->related, true) => CommitMessage::RELATED,
            default => self::LINKED,
        }));
    }

    /**
     * The numbers of the changes the comments of the issue link that Forger has not imported.
     *
     * @param ReadableCollection<int, RelatedChange> $changes the changes of changesOf()
     *
     * @return list<int>
     */
    public static function unknownChanges(Issue $issue, ReadableCollection $changes): array
    {
        $found = $changes->map(static fn (RelatedChange $related): int => $related->change->id)->toArray();

        return array_values(array_diff($issue->changeNumbers(), $found));
    }

    /**
     * The issue the issue is a subtask of, null when it has none.
     */
    public function parentOf(Issue $issue): ?RelatedIssue
    {
        if (null === $issue->parent) {
            return null;
        }

        return new RelatedIssue($issue->parent, self::PARENT, $this->issues->byId($issue->parent));
    }

    /**
     * The subtasks of the issue, the lowest numbers first. The index finds them by their parent, so each of them
     * is in Forger.
     *
     * @return ReadableCollection<int, Issue>
     */
    public function subtasksOf(Issue $issue): ReadableCollection
    {
        return $this->issues->subtasksOf($issue->id);
    }

    /**
     * The issues Forge relates to the issue by their type, as IssueRelationEnum orders them. Duplicates are left
     * out, the duplicates page has them.
     *
     * @return ReadableCollection<int, RelatedIssue>
     */
    public function relatedIssuesOf(Issue $issue): ReadableCollection
    {
        $relations = self::sorted($issue->relations);
        $found = $this->issues->byIds(array_map(static fn (IssueRelation $relation): int => $relation->issue, $relations));

        return new ArrayCollection(array_map(
            static fn (IssueRelation $relation): RelatedIssue => new RelatedIssue(
                $relation->issue,
                $relation->type->label(),
                $found->get($relation->issue),
            ),
            $relations,
        ));
    }

    /**
     * @param list<IssueRelation> $relations
     *
     * @return list<IssueRelation> by their type, as IssueRelationEnum orders them, then by number
     */
    private static function sorted(array $relations): array
    {
        $order = array_flip(array_map(
            static fn (IssueRelationEnum $type): string => $type->value,
            IssueRelationEnum::cases(),
        ));
        usort($relations, static fn (IssueRelation $a, IssueRelation $b): int => [$order[$a->type->value], $a->issue]
            <=> [$order[$b->type->value], $b->issue]);

        return $relations;
    }

    /**
     * The issues of the footer of a change, in its order: those it resolves first.
     *
     * @return ReadableCollection<int, RelatedIssue>
     */
    public function issuesOf(Change $change): ReadableCollection
    {
        $relations = [];
        $footer = [
            CommitMessage::RESOLVES => $change->resolves,
            CommitMessage::RELATED => $change->related,
        ];
        foreach ($footer as $relation => $ids) {
            foreach ($ids as $id) {
                $relations[(int) $id] ??= $relation;
            }
        }
        $found = $this->issues->byIds(array_keys($relations));
        $issues = [];
        foreach ($relations as $id => $relation) {
            $issues[] = new RelatedIssue($id, $relation, $found->get($id));
        }

        return new ArrayCollection($issues);
    }
}
