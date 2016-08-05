<?php
namespace App\Http\Controllers\Graph\Gerrit;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class Full
 *
 * @package WMDB\Forger\Graph\Gerrit
 */
class Full extends AbstractGraph
{

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
                                'reviewstatus' => [
                                    'ABANDONED',
                                    'MERGED'
                                ]
                            ]
                        ],
                        [
                            'query_string' => [
                                'default_field' => 'subject',
                                'query' => 'WIP'
                            ]
                        ]
                    ]
                ],
            ],
            'size' => 0,
        ];
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType('review');
        $result = $search->search();
        $result2 = $result->getTotalHits();

        return $result2;
    }
}
