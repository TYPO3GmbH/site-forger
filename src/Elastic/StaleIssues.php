<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use App\Enum\ActivityEnum;
use App\Enum\IssueSortEnum;
use App\Enum\ReviewStateEnum;
use Elastica\Result;

/**
 * The open Forge issues nobody works on: those in the stage "Stale" or "Orphaned" of ActivityEnum, by default the
 * ones lying still the longest first.
 *
 * Each comes with its changes in Gerrit, so the list shows whether a review waits. A change belongs to an issue when
 * the issue links it in its journals (see GerritLinks) or when its commit message resolves or names the issue. A
 * change the journals link but Forger has not imported has no state.
 */
final readonly class StaleIssues
{
    /**
     * The stages of the issues in the list.
     */
    public const array STAGES = [ActivityEnum::STALE, ActivityEnum::ORPHANED];

    /**
     * The order of the list unless another is picked: those untouched the longest first.
     */
    public const IssueSortEnum DEFAULT_SORT = IssueSortEnum::LEAST_UPDATED;

    /**
     * A page of the issues has not more changes than this.
     */
    private const int MAXIMUM_CHANGES = 1000;

    public function __construct(
        private Indices $indices,
    ) {
    }

    /**
     * A page of the issues, each with its changes, see withChanges().
     *
     * @return array{issues: list<array<string, mixed>>, total: int}
     */
    public function page(int $page, int $perPage, IssueSortEnum $sort): array
    {
        $request = self::issuesRequest($page, $perPage, $sort);
        $resultSet = $this->indices->issues()->createSearch($request)->search();
        $issues = array_map(
            static fn (Result $result): array => $result->getData(),
            $resultSet->getResults(),
        );
        $changesRequest = self::changesRequest($issues);
        $changes = null === $changesRequest ? [] : array_map(
            static fn (Result $result): array => $result->getData(),
            $this->indices->reviews()->createSearch($changesRequest)->search()->getResults(),
        );

        return [
            'issues' => self::withChanges($issues, $changes),
            'total' => $resultSet->getTotalHits(),
        ];
    }

    /**
     * The search request of a page of the issues in an order, the first page is 1.
     *
     * @return array<string, mixed>
     */
    public static function issuesRequest(int $page, int $perPage, IssueSortEnum $sort = self::DEFAULT_SORT): array
    {
        return [
            'query' => ['bool' => [
                'should' => array_map(static fn (ActivityEnum $stage): array => $stage->issueQuery(), self::STAGES),
                'minimum_should_match' => 1,
            ]],
            // The id last, so the pages keep their order among issues of the same value
            'sort' => [...$sort->sort(), ['id' => 'asc']],
            'from' => (max(1, $page) - 1) * $perPage,
            'size' => $perPage,
            'track_total_hits' => true,
        ];
    }

    /**
     * The search request of every change that belongs to one of the issues, in one go, null for no issue.
     *
     * @param list<array<string, mixed>> $issues
     *
     * @return array<string, mixed>|null
     */
    public static function changesRequest(array $issues): ?array
    {
        if ([] === $issues) {
            return null;
        }
        $issueIds = array_map(static fn (array $issue): int => (int) $issue['id'], $issues);
        $should = [
            ['terms' => ['resolves' => $issueIds]],
            ['terms' => ['related' => $issueIds]],
        ];
        $numbers = array_values(array_unique(array_merge(...array_map(GerritLinks::changeNumbers(...), $issues))));
        if ([] !== $numbers) {
            $should[] = ['terms' => ['id' => $numbers]];
        }

        return [
            'query' => ['bool' => ['should' => $should, 'minimum_should_match' => 1]],
            '_source' => ['id', 'reviewstatus', 'work_in_progress', 'resolves', 'related'],
            'sort' => [['id' => 'asc']],
            'size' => self::MAXIMUM_CHANGES,
        ];
    }

    /**
     * The issues, each with its changes in the order of their numbers. The state of a change is null when Forger does
     * not have it.
     *
     * @param list<array<string, mixed>> $issues
     * @param list<array<string, mixed>> $changes the changes of changesRequest()
     *
     * @return list<array{issue: array<string, mixed>, changes: list<array{id: int, state: ReviewStateEnum|null}>}>
     */
    public static function withChanges(array $issues, array $changes): array
    {
        $byNumber = [];
        $byIssue = [];
        foreach ($changes as $change) {
            $number = (int) $change['id'];
            $byNumber[$number] = $change;
            foreach ([...(array) ($change['resolves'] ?? []), ...(array) ($change['related'] ?? [])] as $issueId) {
                $byIssue[(int) $issueId][$number] = $change;
            }
        }

        $rows = [];
        foreach ($issues as $issue) {
            $own = $byIssue[(int) $issue['id']] ?? [];
            foreach (GerritLinks::changeNumbers($issue) as $number) {
                $own[$number] = $byNumber[$number] ?? null;
            }
            ksort($own);
            $states = array_map(static fn (?array $change): ?ReviewStateEnum => null === $change ? null : ReviewStateEnum::fromChange($change), $own);
            $rows[] = [
                'issue' => $issue,
                'changes' => array_map(static fn (int $number, ?ReviewStateEnum $state): array => ['id' => $number, 'state' => $state], array_keys($states), array_values($states)),
            ];
        }

        return $rows;
    }
}
