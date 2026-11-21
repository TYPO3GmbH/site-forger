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
     * The issue the issue is a subtask of, null when it has none or the index does not have it: it may be private,
     * see indexedRelatedIssues().
     */
    public function parentOf(Issue $issue): ?RelatedIssue
    {
        $parent = null === $issue->parent ? null : $this->issues->byId($issue->parent);

        return null === $parent ? null : new RelatedIssue($parent->id, self::PARENT, $parent);
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
     * out, the duplicates page has them. So are the issues the index does not have: they may be private, see
     * indexedRelatedIssues().
     *
     * @return ReadableCollection<int, RelatedIssue>
     */
    public function relatedIssuesOf(Issue $issue): ReadableCollection
    {
        $numbers = array_map(static fn (IssueRelation $relation): int => $relation->issue, $issue->relations);

        return new ArrayCollection(self::related($issue->relations, $this->issues->byIds($numbers)));
    }

    /**
     * @param list<IssueRelation>            $relations
     * @param ReadableCollection<int, Issue> $found     the related issues the index has, by number
     *
     * @return list<RelatedIssue> in the order of sorted(), without the issues the index does not have
     */
    public static function related(array $relations, ReadableCollection $found): array
    {
        $related = [];
        foreach (self::sorted($relations) as $relation) {
            $other = $found->get($relation->issue);
            if (null !== $other) {
                $related[] = new RelatedIssue($relation->issue, $relation->type->label(), $other);
            }
        }

        return $related;
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
     * The numbers of the issues Forge relates to the issue that the index has: the other relations, the duplicates
     * and the parent. The token of the import also sees the private issues, and Forge names them in the relations
     * of public ones. The index has no private issue, so only the numbers it has are public.
     *
     * @return array<int, true> by number
     */
    public function indexedRelatedIssues(Issue $issue): array
    {
        $numbers = [
            ...array_map(static fn (IssueRelation $relation): int => $relation->issue, $issue->relations),
            ...$issue->duplicateNumbers(),
            ...(null === $issue->parent ? [] : [$issue->parent]),
        ];

        return array_fill_keys($this->issues->byIds($numbers)->getKeys(), true);
    }

    /**
     * The history of the issue as its page shows it. It names private issues, too, in relations, as parent or
     * subtask: those changes are left out, and so are the entries nothing is left of. The index has no private issue.
     *
     * @return list<IssueJournal>
     */
    public function historyOf(Issue $issue): array
    {
        $history = $issue->history();
        $numbers = array_merge(...array_map(
            static fn (IssueJournal $journal): array => $journal->issueNumbers(),
            $history,
        ));
        $known = array_fill_keys($this->issues->byIds($numbers)->getKeys(), true);

        return array_values(array_filter(array_map(
            static fn (IssueJournal $journal): ?IssueJournal => $journal->withIssuesOf($known),
            $history,
        )));
    }

    /**
     * The changes of the relation chain of a change as the index has them, by number: their votes and owners, which
     * the chain does not tell. A change of the chain the index does not have is left out.
     *
     * @return ReadableCollection<int, Change>
     */
    public function chainOf(Change $change): ReadableCollection
    {
        return $this->changes->byNumbers(array_map(static fn (ChainLink $link): int => $link->number, $change->chain));
    }

    /**
     * The open changes that conflict with a change as the index has them, by number, see chainOf().
     *
     * @return ReadableCollection<int, Change>
     */
    public function mergeConflictsOf(Change $change): ReadableCollection
    {
        return $this->changes->byNumbers($change->mergeConflicts);
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
