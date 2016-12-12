<?php
namespace App\Http\Controllers\Graph\Gerrit;

use App\Http\Controllers\Graph\AbstractGraph;

class OpenReviews extends AbstractGraph
{

    protected function getData()
    {
        $this->chartData = [
            'chartData' => $this->getReviewsOverTime(),
            'lines' => [
                'open' => [
                    'color' => '#CB00E6',
                    'title' => 'Reviews'
                ]
            ]
        ];
    }

    /**
     * @return string
     */
    protected function getReviewsOverTime()
    {
        $chartData = [];
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'ABANDONED',
                                    'MERGED'
                                ]
                            ],
                        ]
                    ]
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'updated_on',
                        'interval' => 'week'
                    ]
                ]
            ]
        ];
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType('review');
        $resultSet = $search->search();
        $data = $resultSet->getAggregations();
        foreach ($data['over_time']['buckets'] as $frame) {
            $chartData[] = [
                'date' => $this->fixTime($frame['key_as_string']),
                'open' => $frame['doc_count']
            ];
        }

        return $chartData;
    }
}
