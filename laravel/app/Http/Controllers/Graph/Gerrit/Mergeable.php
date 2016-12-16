<?php

namespace App\Http\Controllers\Graph\Gerrit;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class Mergeable.
 */
class Mergeable extends AbstractGraph
{
    /**
     * @return array
     */
    protected function getData()
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        'terms' => [
                            'reviewstatus' => [
                                'NEW',
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'query_string' => [
                                'default_field' => 'subject',
                                'query' => 'WIP',
                            ],
                        ],
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'Mergeable' => [
                    'terms' => [
                        'field' => 'mergeable',
                    ],
                ],
            ],
        ];
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType('review');
        $resultSet = $search->search();
        $data = $resultSet->getAggregations();
        foreach ($data['Mergeable']['buckets'] as $bucket) {
            $this->chartData['chartData'][] = [
                'name' => $bucket['key'],
                'value' => $bucket['doc_count'],
            ];
        }
        $this->chartData['lines'] = [
            'no' => [
                'color' => '#f04124',
                'title' => 'Cannot be merged',
            ],
            'yes' => [
                'color' => '#43ac6a',
                'title' => 'Can be merged',
            ],
        ];
    }
}
