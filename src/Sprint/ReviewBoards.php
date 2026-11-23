<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Document\Change;
use App\Document\Issue;
use App\Enum\ReviewLabelEnum;
use App\Enum\ReviewSortEnum;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Search\ReviewSortQuery;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\ReadableCollection;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The open changes of a sprint board of reviews, see ReviewBoardFilter, grouped by the votes they still need.
 */
final readonly class ReviewBoards
{
    /**
     * The columns of a board of reviews, in their order.
     */
    public const array GROUPS = [
        'missesBoth',
        'missesCodeReview',
        'missesTesting',
        'hasPlusOne',
        'mergeConflict',
        'blocked',
    ];

    private const int MAX_CHANGES = 500;

    private const int MAX_CHANGES_READ = 5000;

    public function __construct(
        private ChangeRepository $changes,
        private IssueRepository $issues,
    ) {
    }

    /**
     * @return array<string, ReadableCollection<int, Change>> by group, each in the order of the sort
     */
    public function cards(ReviewBoardFilter $filter, ReviewSortEnum $sort = ReviewSortEnum::LEAST_UPDATED): array
    {
        $found = $this->changes->search([
            'query' => $filter->toQuery(),
            // With a filter of the issues, more changes are read than shown: some of them go
            'size' => $filter->filtersIssues() ? self::MAX_CHANGES_READ : self::MAX_CHANGES,
            'sort' => ReviewSortQuery::sort($sort),
        ]);
        $changes = $this->withMatchingIssue($found->toArray(), $filter);

        $cards = array_fill_keys(self::GROUPS, []);
        foreach (array_slice($changes, 0, self::MAX_CHANGES) as $change) {
            $cards[self::group($change)][] = $change;
        }

        return array_map(static fn (array $group): ArrayCollection => new ArrayCollection($group), $cards);
    }

    /**
     * @return array<string, int> how many changes each group has, as many as cards() shows
     */
    public function counts(ReviewBoardFilter $filter): array
    {
        return array_map(
            static fn (ReadableCollection $group): int => $group->count(),
            $this->cards($filter),
        );
    }

    /**
     * The changes that name an issue the filter of the issues finds, in "Resolves:" or "Related:". All of them
     * without such a filter, none if its query cannot be read.
     *
     * It asks the issue index only for the issues the changes name: the filter alone can find more issues than a
     * query takes.
     *
     * @param array<array-key, Change> $changes
     *
     * @return list<Change> in the order they came
     */
    private function withMatchingIssue(array $changes, ReviewBoardFilter $filter): array
    {
        if (!$filter->filtersIssues()) {
            return array_values($changes);
        }
        $named = [];
        foreach ($changes as $change) {
            array_push($named, ...$change->resolves, ...$change->related);
        }
        $named = array_values(array_unique($named));
        try {
            $query = Yaml::parse((string) $filter->issueQuery);
        } catch (ParseException) {
            $query = null;
        }
        if (!is_array($query) || [] === $named) {
            return [];
        }
        $found = $this->issues->search([
            'query' => ['bool' => ['filter' => [$query, ['terms' => ['id' => $named]]]]],
            'size' => count($named),
            '_source' => ['id'],
        ]);
        $matching = array_fill_keys($found->map(static fn (Issue $issue): int => $issue->id)->toArray(), true);

        return array_values(array_filter(
            $changes,
            static fn (Change $change): bool => [] !== array_intersect_key(
                array_fill_keys([...$change->resolves, ...$change->related], true),
                $matching,
            ),
        ));
    }

    public static function group(Change $change): string
    {
        if (false === $change->mergeable) {
            return 'mergeConflict';
        }
        $against = $change->negativeVotes(ReviewLabelEnum::CODE_REVIEW)
            + $change->negativeVotes(ReviewLabelEnum::VERIFIED);
        if ($against > 0) {
            return 'blocked';
        }
        $reviewed = $change->positiveVotes(ReviewLabelEnum::CODE_REVIEW) > 0;
        $verified = $change->positiveVotes(ReviewLabelEnum::VERIFIED) > 0;

        return match (true) {
            $reviewed && $verified => 'hasPlusOne',
            $reviewed => 'missesTesting',
            $verified => 'missesCodeReview',
            default => 'missesBoth',
        };
    }
}
