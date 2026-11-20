<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use App\Elastic\Bucket;
use App\Enum\ReviewStatusEnum;

/**
 * The open Gerrit changes: ready to be merged, with a merge conflict, or in progress.
 */
class Mergeable extends ReviewChart
{
    public const array LABELS = [
        'ready' => 'Mergeable',
        'conflict' => 'Merge conflict',
        'wip' => 'Work in progress',
    ];

    /**
     * Colors of the status pills: ready for review, merge conflict, work in progress.
     */
    private const array COLORS = [
        'ready' => '#2a78d6',
        'conflict' => '#d03b3b',
        'wip' => '#fab219',
    ];

    public function getData(): array
    {
        $counts = $this->counts();

        return [
            'labels' => array_values(self::LABELS),
            'datasets' => [
                [
                    'data' => array_values($counts),
                    'backgroundColor' => array_values(self::COLORS),
                    'borderColor' => '#ffffff',
                    'borderWidth' => 2,
                ],
            ],
        ];
    }

    /**
     * The open changes by state. Changes in progress count as such, whether they can be merged or not.
     *
     * @return array{ready: int, conflict: int, wip: int}
     */
    public function counts(): array
    {
        $open = ['term' => ['reviewstatus' => ReviewStatusEnum::NEW->value]];
        $workInProgress = ['term' => ['work_in_progress' => true]];
        $result = $this->fetchData([
            'query' => ['bool' => ['filter' => [$open]]],
            'size' => 0,
            'aggregations' => [
                'state' => ['filters' => ['filters' => [
                    'ready' => ['bool' => ['must_not' => [$workInProgress, ['term' => ['mergeable' => 'no']]]]],
                    'conflict' => ['bool' => ['filter' => [['term' => ['mergeable' => 'no']]], 'must_not' => [$workInProgress]]],
                    'wip' => $workInProgress,
                ]]],
            ],
        ]);
        $counts = Bucket::countsOf($result->getAggregation('state'));

        // Keyed by state: Elasticsearch orders terms buckets by count, which mixed up the states before
        return [
            'ready' => $counts['ready'] ?? 0,
            'conflict' => $counts['conflict'] ?? 0,
            'wip' => $counts['wip'] ?? 0,
        ];
    }
}
