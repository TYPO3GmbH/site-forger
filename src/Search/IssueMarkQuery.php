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
use App\Enum\IssueMarkEnum;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * The queries and the aggregation of the marks of IssueMarkEnum in the issue index.
 */
final class IssueMarkQuery
{
    /**
     * The open issues with the mark: a closed issue needs no help.
     *
     * @return array<string, mixed>
     */
    public static function issues(IssueMarkEnum $mark): array
    {
        return ['bool' => [
            'filter' => [self::markQuery($mark)],
            'must_not' => [IssueSearch::closed()],
        ]];
    }

    /**
     * A "filters" aggregation counting the issues per mark, see facet().
     *
     * @return array<string, mixed>
     */
    public static function aggregation(): array
    {
        $filters = [];
        foreach (IssueMarkEnum::cases() as $mark) {
            $filters[$mark->label()] = self::issues($mark);
        }

        return ['filters' => ['filters' => $filters]];
    }

    /**
     * The buckets of aggregation() as the filter shows them: one per mark, in the order of the marks.
     *
     * @param ReadableCollection<int, Bucket> $facet
     *
     * @return ReadableCollection<int, Bucket>
     */
    public static function facet(ReadableCollection $facet): ReadableCollection
    {
        return FacetSelection::inOrderOfLabels(
            $facet,
            array_map(static fn (IssueMarkEnum $mark): string => $mark->label(), IssueMarkEnum::cases()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function markQuery(IssueMarkEnum $mark): array
    {
        return match ($mark) {
            IssueMarkEnum::REGRESSION => ['term' => ['isregression' => true]],
        };
    }
}
