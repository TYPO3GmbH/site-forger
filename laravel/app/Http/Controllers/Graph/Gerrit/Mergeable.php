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
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData(): array
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
        $resultSet = $this->doSearch($fullRequest, self::DOCUMENT_TYPE_REVIEW);
        $data = $resultSet->getAggregations();
        if (isset($data['Mergeable']['buckets'])) {
            foreach ($data['Mergeable']['buckets'] as $bucket) {
                $this->chartData[] = [
                    'name' => $bucket['key'],
                    'value' => $bucket['doc_count'],
                ];
            }
        }
        // and once for WIP
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'NEW',
                                ],
                            ],
                        ],
                        [
                            'query_string' => [
                                'default_field' => 'subject',
                                'query' => 'WIP',
                            ],
                        ]
                    ],
                ],
            ],
            'size' => 0,
        ];
        $resultSet = $this->doSearch($fullRequest, self::DOCUMENT_TYPE_REVIEW);
        $this->chartData[] = [
            'name' => 'WIP',
            'value' => $resultSet->getTotalHits()
        ];
        return $this->chartData;
    }
}
