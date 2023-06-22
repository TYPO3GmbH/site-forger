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

class TrackerTypes extends IssueChart
{
    public function getData(): array
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must_not' => [
                        'terms' => [
                            'status.name' => [
                                'Closed',
                                'Rejected',
                                'Resolved',
                            ],
                        ],
                    ],
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
        $resultSet = $this->fetchData($fullRequest);
        $data = $resultSet->getAggregations();
        $labelBuckets = $data['trackerType']['buckets'] ?? [];

        $typeColors = [
            'New' => Colors::getAsRgb(Colors::RED),
            'Accepted' => Colors::getAsRgb(Colors::YELLOW),
            'Under Review' => Colors::getAsRgb(Colors::GREEN),
            'Needs Feedback' => Colors::getAsRgb(Colors::BLUE),
            'In Progress' => Colors::getAsRgb(Colors::ORANGE),
            'On Hold' => Colors::getAsRgb(Colors::GREY),
        ];

        // Prepare data
        $labels = [];
        $datasets = [];
        $flatData = [];
        foreach ($labelBuckets as $type) {
            $flatData[$type['key']] = [];
            $labels[] = $type['key'];
            if (is_array($type['status']['buckets'])) {
                foreach ($type['status']['buckets'] as $issueType) {
                    $datasets[$issueType['key']] = [
                        'label' => $issueType['key'],
                        'data' => [],
                        'backgroundColor' => $typeColors[$issueType['key']] ?? Colors::getAsRgb(Colors::GREY),
                    ];
                    $flatData[$type['key']][$issueType['key']] = $issueType['doc_count'];
                }
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
