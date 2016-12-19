<?php

namespace App\Http\Controllers\Graph\Gerrit;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class ByAuthor.
 */
class ByAuthor extends AbstractGraph
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
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'Author' => [
                    'terms' => [
                        'field' => 'owner',
                        'size' => 10000,
                    ],
                ],
            ],
        ];
        $this->chartData = $this->doSearch($fullRequest, self::DOCUMENT_TYPE_REVIEW)->getTotalHits();
    }
}
