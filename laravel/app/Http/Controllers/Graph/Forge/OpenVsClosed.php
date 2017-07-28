<?php

namespace App\Http\Controllers\Graph\Forge;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class OpenVsClosed.
 */
class OpenVsClosed extends AbstractGraph
{
    /**
     * Get's the data.
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData() : array
    {
        $this->chartData = [
            'guides' => $this->getGuides(),
            'chartData' => $this->getClosed(),
            'lines' => [
                'open' => [
                    'color' => '#ff0000',
                    'title' => 'Opened Tickets',
                ],
                'closed' => [
                    'color' => '#43ac6a',
                    'title' => 'Closed Tickets',
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
    protected function getClosed() : array
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
                    'must_not' => [
                        'range' => [
                            'updated_on' => [
                                'lte' => date('Y/m/d h:i:s', time() - (86400 * 60)),
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
                        'interval' => 'day',
                    ],
                ],
            ],
        ];
        $openedTickets = $this->getOpened();
        $resultSet = $this->doSearch($fullRequest);
        $data = $resultSet->getAggregations();
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $time = $this->fixTime($frame['key_as_string']);
                $open = 0;
                if (isset($openedTickets[$time])) {
                    $open = $openedTickets[$time];
                }
                $chartData[] = [
                    'date' => $time,
                    'closed' => $frame['doc_count'],
                    'open' => $open,
                ];
            }
        }

        return $chartData;
    }

    /**
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getOpened() : array
    {
        $chartData = [];
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [

                    ],
                    'must_not' => [
                        [
                            'range' => [
                                'created_on' => [
                                    'lte' => date('Y/m/d h:i:s', time() - (86400 * 60)),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'created_on',
                        'interval' => 'day',
                    ],
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest);
        $data = $resultSet->getAggregations();
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $chartData[$this->fixTime($frame['key_as_string'])] = $frame['doc_count'];
            }
        }

        return $chartData;
    }
}
