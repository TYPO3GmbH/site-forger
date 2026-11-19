<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

/**
 * A filter with many values, like people or tags, shows the few with the most results only, and the selected ones.
 *
 * Other people are found with the search in the filter, see PersonSuggestion. A selected value that is not
 * among those with the most results is asked for with an aggregation of its own and added to the filter.
 */
final class FacetSelection
{
    /**
     * People a filter shows without searching.
     */
    public const int SIZE = 10;

    private const string SELECTED = ' (selected)';

    /**
     * The aggregation of the filter.
     *
     * @param int $size how many values the filter shows without searching
     *
     * @return array<string, mixed>
     */
    public static function aggregation(string $field, int $size = self::SIZE): array
    {
        return ['terms' => ['field' => $field, 'size' => $size]];
    }

    /**
     * Adds the aggregation of the selected people to the aggregations of the request, if any are selected.
     *
     * @param array<string, mixed> $aggregations
     * @param list<string>         $selected     the selected values, as they are stored
     *
     * @return array<string, mixed>
     */
    public static function withSelected(array $aggregations, string $title, array $selected): array
    {
        if (!isset($aggregations[$title]['terms']['field']) || [] === $selected) {
            return $aggregations;
        }
        $aggregations[$title . self::SELECTED] = [
            'terms' => ['field' => $aggregations[$title]['terms']['field'], 'include' => array_values($selected), 'size' => count($selected)],
        ];

        return $aggregations;
    }

    /**
     * Moves the selected people of the response into the filter, those it does not show yet.
     *
     * @param array<string, mixed> $aggregations
     *
     * @return array<string, mixed>
     */
    public static function merge(array $aggregations, string $title): array
    {
        $selected = $aggregations[$title . self::SELECTED]['buckets'] ?? [];
        unset($aggregations[$title . self::SELECTED]);
        if (!isset($aggregations[$title]['buckets'])) {
            return $aggregations;
        }
        $shown = array_column($aggregations[$title]['buckets'], 'key');
        foreach ($selected as $bucket) {
            if (!in_array($bucket['key'], $shown, true)) {
                $aggregations[$title]['buckets'][] = $bucket;
            }
        }

        return $aggregations;
    }

    /**
     * The values of "filters[<key>][<value>]=true", as they are stored: the search writes spaces as "_".
     *
     * @param array<string, mixed> $filters
     *
     * @return list<string>
     */
    public static function selected(array $filters, string $key): array
    {
        $values = [];
        foreach ((array) ($filters[$key] ?? []) as $value => $enabled) {
            if ('true' === $enabled) {
                $values[] = str_replace('_', ' ', (string) $value);
            }
        }

        return $values;
    }
}
