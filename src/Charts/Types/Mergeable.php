<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use Elastica\Index;

class Mergeable extends ReviewChart
{
    public function getData(): array
    {
        return [
            'labels' => ['Yes', 'No', 'WIP'],
            'datasets' => [
                [
                    'data' => array_merge($this->getMergeable(), [
                        $this->getWorkInProgress(),
                    ]),
                    'backgroundColor' => [
                        'rgb(75, 192, 192)',
                        'rgb(255, 99, 132)',
                        'rgb(54, 162, 235)',
                    ],
                ],
            ],
        ];
    }

    private function getMergeable(): array
    {
        $query = [
            'query' => [
                'bool' => [
                    'must' => [
                        'terms' => ['reviewstatus' => ['NEW']],
                    ],
                    'must_not' => [
                        [
                            'terms' => ['reviewtags' => ['WIP']],
                        ],
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'Mergeable' => [
                    'terms' => ['field' => 'mergeable'],
                ],
            ],
        ];
        $search = $this->index->createSearch($query);
        $search->addType($this->getDocumentType());
        $resultSet = $search->search();
        $data = $resultSet->getAggregations();

        $chartData = [];
        if (isset($data['Mergeable']['buckets'])) {
            foreach ($data['Mergeable']['buckets'] as $bucket) {
                $chartData[] = $bucket['doc_count'];
            }
        }

        return $chartData;
    }

    private function getWorkInProgress(): int
    {
        $query = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'terms' => ['reviewstatus' => ['NEW']],
                        ],
                        [
                            'terms' => ['reviewtags' => ['WIP']],
                        ]
                    ],
                ],
            ],
            'size' => 0,
        ];

        $search = $this->index->createSearch($query);
        $search->addType($this->getDocumentType());
        $resultSet = $search->search();
        return $resultSet->getTotalHits();
    }
}
