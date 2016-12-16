<?php

namespace App\Http\Controllers\Graph\Gerrit;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class Full.
 */
class Full extends AbstractGraph
{
    /**
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData()
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'ABANDONED',
                                    'MERGED',
                                ],
                            ],
                        ],
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
        ];
        $this->chartData = $this->doSearch($fullRequest, self::DOCUMENT_TYPE_REVIEW)->getTotalHits();
    }
}
