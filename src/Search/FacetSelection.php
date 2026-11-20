<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Elastic\Bucket;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\ReadableCollection;

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
     * @param array<string, ReadableCollection<int, Bucket>> $facets see SearchResult::facets()
     *
     * @return array<string, ReadableCollection<int, Bucket>>
     */
    public static function merge(array $facets, string $title): array
    {
        $selected = $facets[$title . self::SELECTED] ?? new ArrayCollection();
        unset($facets[$title . self::SELECTED]);
        if (!isset($facets[$title])) {
            return $facets;
        }
        $shown = $facets[$title]->map(static fn (Bucket $bucket): string => $bucket->key)->toArray();
        $missing = $selected->filter(static fn (Bucket $bucket): bool => !in_array($bucket->key, $shown, true));
        $facets[$title] = new ArrayCollection([...$facets[$title]->getValues(), ...$missing->getValues()]);

        return $facets;
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
     * @template T
     *
     * @param array<string, T> $facets
     * @param list<string>     $titles
     *
     * @return array<string, T>
     */
    public static function inOrderOf(array $facets, array $titles): array
    {
        $ordered = [];
        foreach ($titles as $title) {
            if (array_key_exists($title, $facets)) {
                $ordered[$title] = $facets[$title];
            }
        }

        return $ordered + $facets;
    }
}
