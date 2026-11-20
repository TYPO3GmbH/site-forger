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
use App\Enum\ActivityEnum;
use App\Enum\ReviewStatusEnum;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * The queries and the aggregations of the stages of ActivityEnum in the review and the issue index.
 */
final class ActivityQuery
{
    /**
     * The field of the review and the issue index the stage is derived from.
     */
    public const string FIELD = 'updated_on';

    /**
     * The range of the stage in Elasticsearch date math: "gte" is included, "lt" is not.
     *
     * @return array{gte?: string, lt?: string}
     */
    public static function range(ActivityEnum $stage): array
    {
        $range = [];
        if (null !== $stage->maximumDays()) {
            $range['gte'] = 'now-' . $stage->maximumDays() . 'd';
        }
        if ($stage->minimumDays() > 0) {
            $range['lt'] = 'now-' . $stage->minimumDays() . 'd';
        }

        return $range;
    }

    /**
     * The open Gerrit changes of the review index in the stage: merged and abandoned ones have none.
     *
     * @return array<string, mixed>
     */
    public static function changes(ActivityEnum $stage): array
    {
        return self::open($stage, ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]]);
    }

    /**
     * The open Forge issues of the issue index in the stage: resolved, closed and rejected ones have none.
     *
     * @return array<string, mixed>
     */
    public static function issues(ActivityEnum $stage): array
    {
        return self::open($stage, IssueSearch::closed());
    }

    /**
     * A "filters" aggregation counting the open Gerrit changes per stage, see facet().
     *
     * @return array<string, mixed>
     */
    public static function changeAggregation(): array
    {
        return self::aggregation(self::changes(...));
    }

    /**
     * A "filters" aggregation counting the open Forge issues per stage, see facet().
     *
     * @return array<string, mixed>
     */
    public static function issueAggregation(): array
    {
        return self::aggregation(self::issues(...));
    }

    /**
     * The buckets of changeAggregation() or issueAggregation() as the filter shows them: one per stage, in the
     * order of the stages.
     *
     * @param ReadableCollection<int, Bucket> $facet
     *
     * @return ReadableCollection<int, Bucket>
     */
    public static function facet(ReadableCollection $facet): ReadableCollection
    {
        return FacetSelection::inOrderOfLabels(
            $facet,
            array_map(static fn (ActivityEnum $stage): string => $stage->label(), ActivityEnum::cases()),
        );
    }

    /**
     * @param array<string, mixed> $closed
     *
     * @return array<string, mixed>
     */
    private static function open(ActivityEnum $stage, array $closed): array
    {
        return ['bool' => [
            'filter' => [['range' => [self::FIELD => self::range($stage)]]],
            'must_not' => [$closed],
        ]];
    }

    /**
     * @param \Closure(ActivityEnum): array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private static function aggregation(\Closure $query): array
    {
        $filters = [];
        foreach (ActivityEnum::cases() as $stage) {
            $filters[$stage->label()] = $query($stage);
        }

        return ['filters' => ['filters' => $filters]];
    }
}
