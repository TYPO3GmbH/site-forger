<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use App\Charts\Colors;
use App\Enum\IssueStatusEnum;
use App\Search\IssueSearch;

class TrackerTypes extends IssueChart
{
    public function getData(): array
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must_not' => [IssueSearch::closed()],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'trackerType' => [
                    'terms' => [
                        'field' => 'tracker.name',
                    ],
                    'aggregations' => [
                        'status' => [
                            'terms' => [
                                'field' => 'status.name',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $trackers = $this->fetchData($fullRequest)->buckets('trackerType');

        $typeColors = [
            IssueStatusEnum::NEW->label() => Colors::getAsRgb(Colors::RED),
            IssueStatusEnum::ACCEPTED->label() => Colors::getAsRgb(Colors::YELLOW),
            IssueStatusEnum::UNDER_REVIEW->label() => Colors::getAsRgb(Colors::GREEN),
            IssueStatusEnum::NEEDS_FEEDBACK->label() => Colors::getAsRgb(Colors::BLUE),
            IssueStatusEnum::IN_PROGRESS->label() => Colors::getAsRgb(Colors::ORANGE),
            IssueStatusEnum::ON_HOLD->label() => Colors::getAsRgb(Colors::GREY),
        ];

        // Prepare data
        $labels = [];
        $datasets = [];
        $flatData = [];
        foreach ($trackers as $tracker) {
            $flatData[$tracker->key] = [];
            $labels[] = $tracker->key;
            foreach ($tracker->buckets('status') as $status) {
                $datasets[$status->key] = [
                    'label' => $status->key,
                    'data' => [],
                    'backgroundColor' => $typeColors[$status->key] ?? Colors::getAsRgb(Colors::GREY),
                ];
                $flatData[$tracker->key][$status->key] = $status->count;
            }
        }

        // Fill dataset
        foreach ($datasets as $key => $values) {
            foreach ($labels as $label) {
                $datasets[$key]['data'][$label] = $flatData[$label][$key] ?? 0;
            }
            $datasets[$key]['data'] = array_values($datasets[$key]['data']);
        }
        $datasets = array_values($datasets);

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }
}
