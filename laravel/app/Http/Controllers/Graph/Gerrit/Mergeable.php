<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

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
                            'terms' => [
                                'reviewtags' => [
                                    'WIP'
                                ]
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
                            'terms' => [
                                'reviewtags' => [
                                    'WIP'
                                ]
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
