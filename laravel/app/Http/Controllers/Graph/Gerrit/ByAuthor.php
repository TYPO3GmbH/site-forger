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
 * Class ByAuthor.
 */
class ByAuthor extends AbstractGraph
{
    /**
     * @return array
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData() : array
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
        return [$this->chartData];
    }
}
