<?php

namespace App\Http\Controllers\Graph\Forge;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class TrackerTypes.
 */
class TrackerTypes extends AbstractGraph
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
            'size' => 0,
            'aggregations' => [
                'trackerType' => [
                    'terms' => [
                        'field' => 'tracker.name',
                    ],
                    'aggregations' => [
                        'status' => [
                            'terms' => [
                                'field' => 'status.name',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest);
        $data = $resultSet->getAggregations();
        $i = 0;
        if (\is_array($data['trackerType']['buckets'])) {
            foreach ($data['trackerType']['buckets'] as $frame) {
                if (\is_array($frame['status']['buckets'])) {
                    foreach ($frame['status']['buckets'] as $issueType) {
                        $this->chartData[$i][$this->fixKey($issueType['key'])] = $issueType['doc_count'];
                    }
                    $this->chartData[$i]['panel'] = $frame['key'];
                    ++$i;
                }
            }
        }
        return $this->chartData;
    }

    /**
     * Makes the array keys lowercase and removes spaces.
     *
     * @param string $in
     *
     * @return string
     */
    protected function fixKey($in) : string
    {
        return str_replace(' ', '', ucwords($in));
    }
}
