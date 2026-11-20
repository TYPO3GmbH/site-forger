<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use App\Enum\IssueStatusEnum;
use App\Enum\TrackerEnum;

/**
 * The table of "Issues by category": per category its open issues, how many of them each tracker has.
 */
final class CategoryOverview
{
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
                ['terms' => ['status.name' => IssueStatusEnum::closedLabels()]],
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
     * @param list<array<string, mixed>> $buckets
     *
     * @return list<array{name: string, total: int, counts: array<string, int>, other: array{count: int, labels: list<string>}}>
     */
    public static function rows(array $buckets): array
    {
        $rows = array_map(self::row(...), $buckets);
        usort($rows, static fn (array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        return $rows;
    }

    /**
     * The row of the open issues without a category, null if every open issue has one. The bucket is the aggregation
     * "without_category" of request().
     *
     * @param array<string, mixed>|null $bucket
     *
     * @return array{name: string, total: int, counts: array<string, int>, other: array{count: int, labels: list<string>}}|null
     */
    public static function withoutCategory(?array $bucket): ?array
    {
        if (null === $bucket || 0 === (int) ($bucket['doc_count'] ?? 0)) {
            return null;
        }

        return self::row(['key' => 'Without category'] + $bucket);
    }

    /**
     * @param array<string, mixed> $bucket
     *
     * @return array{name: string, total: int, counts: array<string, int>, other: array{count: int, labels: list<string>}}
     */
    private static function row(array $bucket): array
    {
        $counts = array_fill_keys(array_map(static fn (TrackerEnum $tracker): string => $tracker->label(), self::TRACKERS), 0);
        $other = ['count' => 0, 'labels' => []];
        foreach ($bucket['tracker']['buckets'] ?? [] as $tracker) {
            $label = (string) $tracker['key'];
            if (array_key_exists($label, $counts)) {
                $counts[$label] = (int) $tracker['doc_count'];
                continue;
            }
            $other['count'] += (int) $tracker['doc_count'];
            $other['labels'][] = $label;
        }
        sort($other['labels']);

        return [
            'name' => (string) $bucket['key'],
            // All its open issues, also those without a tracker the aggregation lists
            'total' => (int) $bucket['doc_count'],
            'counts' => $counts,
            'other' => $other,
        ];
    }

    /**
     * The sums of the columns, for the last row of the table.
     *
     * @param list<array{name: string, total: int, counts: array<string, int>, other: array{count: int, labels: list<string>}}> $rows
     *
     * @return array{total: int, counts: array<string, int>, other: int}
     */
    public static function sums(array $rows): array
    {
        $counts = array_fill_keys(array_map(static fn (TrackerEnum $tracker): string => $tracker->label(), self::TRACKERS), 0);
        foreach ($rows as $row) {
            foreach ($row['counts'] as $label => $count) {
                $counts[$label] += $count;
            }
        }

        return [
            'total' => array_sum(array_column($rows, 'total')),
            'counts' => $counts,
            'other' => array_sum(array_map(static fn (array $row): int => $row['other']['count'], $rows)),
        ];
    }
}
