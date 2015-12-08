<?php
namespace App\Http\Controllers\Graph\Forge;

use App\Http\Controllers\Graph\AbstractGraph;

class Full extends AbstractGraph
{

    protected function getData()
    {
        $this->chartData = $this->getAllOpenIssues();
    }

    /**********************************************
     * REDMINE RELATED THINGS
     ***********************************************/
    protected function getAllOpenIssues()
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must_not' => [
                        [
                            'terms' => [
                                'status.name' => [
                                    'Closed',
                                    'Rejected',
                                    'Resolved'
                                ]
                            ],
                        ]
                    ]
                ],
            ],
            'size' => 0,
        ];
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType('issue');
        $result = $search->search();
        $result2 = $result->getTotalHits();

        return $result2;
    }
}
