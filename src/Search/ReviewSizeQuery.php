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
use App\Enum\ReviewSizeEnum;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * The queries and the aggregation of the sizes of ReviewSizeEnum in the review index.
 */
final class ReviewSizeQuery
{
    /**
     * The lines of a change in Painless, 0 for one imported before the lines were.
     */
    public const string LINES_SCRIPT = "(doc['insertions'].size() == 0 ? 0 : doc['insertions'].value)"
        . " + (doc['deletions'].size() == 0 ? 0 : doc['deletions'].value)";

    /**
     * The Gerrit changes of the review index of the size. A change imported without lines has none.
     *
     * @return array<string, mixed>
     */
    public static function changes(ReviewSizeEnum $size): array
    {
        return ['bool' => ['filter' => [
            ['exists' => ['field' => 'insertions']],
            ['script' => ['script' => [
                'lang' => 'painless',
                'source' => 'def lines = ' . self::LINES_SCRIPT . ';'
                    . ' return lines >= params.minimum && (params.maximum < 0 || lines <= params.maximum);',
                'params' => ['minimum' => $size->minimum(), 'maximum' => $size->maximum() ?? -1],
            ]]],
        ]]];
    }

    /**
     * The Gerrit changes with at most this many lines added and removed together. A change imported without lines
     * counts as empty.
     *
     * @return array<string, mixed>
     */
    public static function atMost(int $lines): array
    {
        return ['script' => ['script' => [
            'lang' => 'painless',
            'source' => self::LINES_SCRIPT . ' <= params.maximum',
            'params' => ['maximum' => $lines],
        ]]];
    }

    /**
     * A "filters" aggregation counting the changes per size, see facet().
     *
     * @return array<string, mixed>
     */
    public static function changeAggregation(): array
    {
        $filters = [];
        foreach (ReviewSizeEnum::cases() as $size) {
            $filters[$size->label()] = self::changes($size);
        }

        return ['filters' => ['filters' => $filters]];
    }

    /**
     * The buckets of changeAggregation() as the filter shows them: one per size, the smallest first.
     *
     * @param ReadableCollection<int, Bucket> $facet
     *
     * @return ReadableCollection<int, Bucket>
     */
    public static function facet(ReadableCollection $facet): ReadableCollection
    {
        return FacetSelection::inOrderOfLabels(
            $facet,
            array_map(static fn (ReviewSizeEnum $size): string => $size->label(), ReviewSizeEnum::cases()),
        );
    }
}
