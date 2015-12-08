<?php
namespace App\Http\Controllers\Graph\Gerrit;

use App\Http\Controllers\Graph\AbstractGraph;

class ByAuthor extends AbstractGraph
{

    /**
     *
     */
    protected function getData()
    {
        $this->chartData = $this->getAllOpenReviews();
    }

    /**********************************************
     * GERRIT RELATED THINGS
     ***********************************************/
    protected function getAllOpenReviews()
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must_not' => [
                        [
                            'terms' => [
                                'status' => [
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
                'Author' => [
                    'terms' => [
                        'field' => 'owner',
                        'size' => 10000
                    ],
                ]
            ]
        ];
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType('review');
        $result = $search->search();
        $aggs = $result->getAggregations();
        $result2 = $result->getTotalHits();

        return $result2;
    }
}
