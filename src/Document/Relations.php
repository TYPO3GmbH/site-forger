<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Elastic\Indices;
use App\Enum\IssueStatusEnum;
use Elastica\ResultSet;

/**
 * How issues and changes belong together, for the pages of an issue and of a change.
 */
final readonly class Relations
{
    /**
     * The page of an issue shows this many changes at most, the ones updated last first.
     */
    private const int CHANGES = 50;

    /**
     * The duplicates page shows this many issues, the most similar first.
     */
    private const int DUPLICATES = 25;

    private const string LINKED = 'Linked';

    public function __construct(
        private Indices $indices,
    ) {
    }

    /**
     * The changes whose footer resolves or names the issue, and those its comments link.
     * "unknown" has the numbers the comments link that are not imported.
     *
     * @param array<string, mixed> $issue
     *
     * @return array{changes: list<array{change: array<string, mixed>, relation: string}>, unknown: list<int>}
     */
    public function changesOf(array $issue): array
    {
        $id = (int) $issue['id'];
        $numbers = GerritLinks::changeNumbers($issue);
        $results = $this->indices->reviews()->search([
            'query' => ['bool' => ['should' => [
                ['term' => ['resolves' => $id]],
                ['term' => ['related' => $id]],
                ['terms' => ['id' => [] === $numbers ? [0] : $numbers]],
            ]]],
            'sort' => [['updated_on' => 'desc']],
            'size' => self::CHANGES,
        ])->getResults();
        $changes = [];
        foreach ($results as $result) {
            $change = $result->getData();
            $changes[] = ['change' => $change, 'relation' => match (true) {
                in_array($id, $change['resolves'] ?? [], true) => CommitMessage::RESOLVES,
                in_array($id, $change['related'] ?? [], true) => CommitMessage::RELATED,
                default => self::LINKED,
            }];
        }
        $found = array_map(static fn (array $change): int => (int) $change['change']['id'], $changes);

        return ['changes' => $changes, 'unknown' => array_values(array_diff($numbers, $found))];
    }

    /**
     * The issues of the footer of a change, in its order: those it resolves first.
     * An issue that is not imported comes as null: it is in another project, or private.
     *
     * @param array<string, mixed> $change
     *
     * @return list<array{id: int, relation: string, issue: array<string, mixed>|null}>
     */
    public function issuesOf(array $change): array
    {
        $relations = [];
        $footer = [
            CommitMessage::RESOLVES => $change['resolves'] ?? [],
            CommitMessage::RELATED => $change['related'] ?? [],
        ];
        foreach ($footer as $relation => $ids) {
            foreach ($ids as $id) {
                $relations[(int) $id] ??= $relation;
            }
        }
        $found = [];
        if ([] !== $relations) {
            $results = $this->indices->issues()->search([
                'query' => ['terms' => ['id' => array_keys($relations)]],
                'size' => count($relations),
            ])->getResults();
            foreach ($results as $result) {
                $found[(int) $result->getData()['id']] = $result->getData();
            }
        }
        $issues = [];
        foreach ($relations as $id => $relation) {
            $issues[] = ['id' => $id, 'relation' => $relation, 'issue' => $found[$id] ?? null];
        }

        return $issues;
    }

    /**
     * The open issues most like the issue, all issues with $includeClosed.
     */
    public function duplicatesOf(int $id, bool $includeClosed): ResultSet
    {
        $bool = [
            'must' => [[
                'more_like_this' => [
                    'fields' => ['subject', 'description'],
                    'like' => [['_id' => (string) $id]],
                    'min_term_freq' => 2,
                    'max_query_terms' => 25,
                    'minimum_should_match' => '50%',
                ],
            ]],
        ];
        if (!$includeClosed) {
            $bool['must_not'] = [['terms' => ['status.name' => IssueStatusEnum::closedLabels()]]];
        }

        return $this->indices->issues()->search([
            'query' => ['bool' => $bool],
            'size' => self::DUPLICATES,
        ]);
    }
}
