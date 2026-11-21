<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report;

use App\Elastic\Bucket;
use App\Enum\TrackerEnum;
use App\Search\IssueSearch;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * The table of "Issues by category": per category its open issues, how many of them each tracker has.
 */
final class CategoryOverview
{
    /**
     * The name of the issues without category: their row, and their value in the filter "Category" of the search,
     * see the filter query withoutCategoryQuery().
     */
    public const string WITHOUT = 'Without category';

    /**
     * The issues without category, for the filter "Category" of the search.
     *
     * @return array<string, mixed>
     */
    public static function withoutCategoryQuery(): array
    {
        return ['bool' => ['must_not' => [['exists' => ['field' => 'category.name']]]]];
    }

    /**
     * The trackers of the TYPO3 Core team, in the order of the work: what is broken, then what is new. Each has a column
     * of its own, the others, rare in every category, share the column "Other".
     */
    public const array TRACKERS = [TrackerEnum::BUG, TrackerEnum::FEATURE, TrackerEnum::TASK, TrackerEnum::STORY, TrackerEnum::EPIC];

    /**
     * The search request: the open issues of the TYPO3 Core, counted per category and tracker.
     *
     * @return array<string, mixed>
     */
    public static function request(): array
    {
        return [
            'query' => ['bool' => ['must_not' => [
                IssueSearch::closed(),
                // Not an issue of the TYPO3 Core
                ['terms' => ['project.id' => ['78']]],
            ]]],
            'size' => 0,
            'track_total_hits' => true,
            'aggregations' => [
                'category' => [
                    'terms' => ['field' => 'category.name', 'size' => 1000],
                    // Every tracker of Forge
                    'aggregations' => ['tracker' => ['terms' => ['field' => 'tracker.name', 'size' => 20]]],
                ],
                // The open issues no category was given to, counted the same way
                'without_category' => [
                    'missing' => ['field' => 'category.name'],
                    'aggregations' => ['tracker' => ['terms' => ['field' => 'tracker.name', 'size' => 20]]],
                ],
            ],
        ];
    }

    /**
     * The rows of the table, the categories with the most open issues first, the same counts by name. The counts by the
     * label of each of TRACKERS, 0 for none. The buckets are those of the aggregation "category" of request().
     *
     * @param ReadableCollection<array-key, Bucket> $categories
     *
     * @return list<CategoryRow>
     */
    public static function rows(ReadableCollection $categories): array
    {
        $rows = array_values($categories->map(self::row(...))->toArray());
        usort(
            $rows,
            static fn (CategoryRow $a, CategoryRow $b): int => [$b->total, $a->name] <=> [$a->total, $b->name],
        );

        return $rows;
    }

    /**
     * The row of the open issues without a category, null if every open issue has one. The bucket is the aggregation
     * "without_category" of request().
     *
     * @param array<string, mixed>|null $bucket
     */
    public static function withoutCategory(?array $bucket): ?CategoryRow
    {
        $category = null === $bucket ? null : Bucket::fromArray($bucket, self::WITHOUT);
        if (null === $category || 0 === $category->count) {
            return null;
        }

        return self::row($category);
    }

    private static function row(Bucket $category): CategoryRow
    {
        $counts = self::noCounts();
        $otherCount = 0;
        $otherLabels = [];
        foreach ($category->buckets('tracker') as $tracker) {
            if (array_key_exists($tracker->key, $counts)) {
                $counts[$tracker->key] = $tracker->count;
                continue;
            }
            $otherCount += $tracker->count;
            $otherLabels[] = $tracker->key;
        }
        sort($otherLabels);

        // The total counts all its open issues, also those without a tracker the aggregation lists
        return new CategoryRow($category->key, $category->count, $counts, new OtherTrackers($otherCount, $otherLabels));
    }

    /**
     * The sums of the columns, for the last row of the table.
     *
     * @param list<CategoryRow> $rows
     */
    public static function sums(array $rows): CategorySums
    {
        $counts = self::noCounts();
        $total = 0;
        $other = 0;
        foreach ($rows as $row) {
            foreach ($row->counts as $label => $count) {
                $counts[$label] += $count;
            }
            $total += $row->total;
            $other += $row->other->count;
        }

        return new CategorySums($total, $counts, $other);
    }

    /**
     * @return array<string, int> 0 by the label of each of TRACKERS
     */
    private static function noCounts(): array
    {
        return array_fill_keys(
            array_map(static fn (TrackerEnum $tracker): string => $tracker->label(), self::TRACKERS),
            0,
        );
    }
}
