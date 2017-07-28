<?php

namespace App\Http\Controllers\Graph\Forge;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class CycleTime.
 */
class CycleTime extends AbstractGraph
{
    /**
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData() : array
    {
        $this->chartData = [
            'chartData' => $this->getCycleTime(),
            'lines' => [
                'cycleTime' => [
                    'color' => '#E69D00',
                    'title' => 'Days',
                ],
            ],
        ];

        return $this->chartData;
    }

    /**
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getCycleTime() : array
    {
        $chartData = [];
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
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
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'updated_on',
                        'interval' => 'month',
                    ],
                    'aggregations' => [
                        'cycle' => [
                            'stats' => [
                                'script' => '((doc["updated_on"].value-doc["created_on"].value) / (1000 * 86400))',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest);
        $data = $resultSet->getAggregations();
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $chartData[] = [
                    'date' => $this->fixTime($frame['key_as_string']),
                    'cycleTime' => floor($frame['cycle']['avg']),
                ];
            }
        }

        return $chartData;
    }
}
