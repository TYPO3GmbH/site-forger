<?php

namespace App\Http\Controllers\Graph\Gerrit;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class OpenReviews.
 */
class OpenReviews extends AbstractGraph
{
    /**
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData()
    {
        $this->chartData = [
            'chartData' => $this->getReviewsOverTime(),
            'lines' => [
                'open' => [
                    'color' => '#CB00E6',
                    'title' => 'Reviews',
                ],
            ],
        ];
    }

    /**
     * @return string
     *
     * @throws \Elastica\Exception\InvalidException
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
                                    'MERGED',
                                ],
                            ],
                        ],
                        [
                            [
                                'term' => [
                                    'subject' => 'wip',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'updated_on',
                        'interval' => 'week',
                    ],
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest, self::DOCUMENT_TYPE_REVIEW);
        $data = $resultSet->getAggregations();
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $chartData[] = [
                    'date' => $this->fixTime($frame['key_as_string']),
                    'open' => $frame['doc_count'],
                ];
            }
        }

        return $chartData;
    }
}
