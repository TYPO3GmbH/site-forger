<?php

namespace App\Http\Controllers\Graph\Forge;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class Full.
 */
class Full extends AbstractGraph
{
    /**
     * @return array
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData() : array
    {
        $this->chartData = $this->getAllOpenIssues();
        return [$this->chartData];
    }

    /**********************************************
     * REDMINE RELATED THINGS
     ***********************************************/
    /**
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getAllOpenIssues() : array
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
                                    'Resolved',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'size' => 0,
        ];
        $resultSet = $this->doSearch($fullRequest);

        return ['total' => $resultSet->getTotalHits()];
    }
}
