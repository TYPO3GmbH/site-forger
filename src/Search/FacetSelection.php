<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

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

    /**
     * How many values each shard tells: the indices have several, and a value counts only on the shards that tell it
     * among their most. With all of them told the counts are exact: there are some thousands of people.
     */
    public const int SHARD_SIZE = 5000;

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
        return ['terms' => ['field' => $field, 'size' => $size, 'shard_size' => max($size, self::SHARD_SIZE)]];
    }

    /**
     * Adds the aggregation of the selected people to the aggregations of the request, if any are selected.
     *
     * @param array<string, mixed> $aggregations
     * @param list<string|int>     $selected     the selected values, as the field has them: the numbers of people
     *
     * @return array<string, mixed>
     */
    public static function withSelected(array $aggregations, string $title, array $selected): array
    {
        if (!isset($aggregations[$title]['terms']['field']) || [] === $selected) {
            return $aggregations;
        }
        // With the aggregations within, like the latest document of a person, see PeopleFacet
        $aggregations[$title . self::SELECTED] = [
            'terms' => ['field' => $aggregations[$title]['terms']['field'], 'include' => $selected, 'size' => count($selected)],
        ] + array_intersect_key($aggregations[$title], ['aggs' => true, 'aggregations' => true]);

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
        return array_map(static fn (string $value): string => str_replace('_', ' ', $value), self::keys($filters, $key));
    }

    /**
     * The keys of "filters[<key>][<value>]=true" as they are sent, like the people, see PeopleFacet: a username may
     * have a "_" of its own.
     *
     * @param array<string, mixed> $filters
     *
     * @return list<string>
     */
    public static function keys(array $filters, string $key): array
    {
        $keys = [];
        foreach ((array) ($filters[$key] ?? []) as $value => $enabled) {
            if ('true' === $enabled) {
                $keys[] = (string) $value;
            }
        }

        return $keys;
    }

    /**
     * Elasticsearch answers the aggregations in an order of its own. This puts them in the order of their titles,
     * the others after them.
     *
     * @param array<string, mixed> $aggregations
     * @param list<string>         $titles
     *
     * @return array<string, mixed>
     */
    public static function inOrderOf(array $aggregations, array $titles): array
    {
        return array_replace(array_intersect_key(array_flip($titles), $aggregations), $aggregations);
    }
}
