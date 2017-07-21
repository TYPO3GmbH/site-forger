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
                'trackertype' => [
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
        if (is_array($data['trackertype']['buckets'])) {
            foreach ($data['trackertype']['buckets'] as $frame) {
                if (is_array($frame['status']['buckets'])) {
                    foreach ($frame['status']['buckets'] as $issueType) {
                        $this->chartData['bars'][$i][$this->fixKey($issueType['key'])] = $issueType['doc_count'];
                    }
                    $this->chartData['bars'][$i]['panel'] = $frame['key'];
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
        $fixedString = strtolower(str_replace(' ', '', $in));

        // Fixed Lookup colors
        $colors = [
            'new' => '#fb7e7e',
            'accepted' => '#fbee99',
            'needsfeedback' => '#FBC3C3',
            'inprogress' => '#A9E2FF',
            'onhold' => '#D1F412',
            'underreview' => '#aeff91',
            'readyfordeployment' => '#ff9900'
        ];
        $this->chartData['lookup'][$fixedString] = [
            'lookup' => $fixedString,
            'title' => $in,
            'color' => $colors[$fixedString] ?? '#f2f2f2',
        ];

        return $fixedString;
    }
}
