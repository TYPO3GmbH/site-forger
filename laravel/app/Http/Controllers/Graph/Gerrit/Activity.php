<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http\Controllers\Graph\Gerrit;

use App\Elastic\Connection;
use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class Velocity
 */
class Activity extends AbstractGraph
{
    /**
     * Velocity constructor.
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        parent::__construct($connection);
        $this->dateInterval = 'month';
    }

    /**
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData() : array
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'interacted_on' => [
                                    'gte' => '2010/01/01 00:00:00'
                                ]
                            ]
                        ]
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'interacted_on',
                        'interval' => 'month',
                    ],
                    'aggregations' => [
                        'interaction_type' => [
                            'terms' => [
                                'field' => 'interaction_type',
                            ],
                        ]
                    ]
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest, 'interactions');
        $data = $resultSet->getAggregations();
        if (\is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $aggregations = [
                    'Comment' => 0,
                    'Verification' => 0,
                    'Added_Patchset' => 0,
                    'Code_Review' => 0,
                    'Merge' => 0,
                    'Abandoned_Patch' => 0,
                ];
                foreach ($frame['interaction_type']['buckets'] as $interactionType) {
                    if ($interactionType['key'] === 'Comment') {
                        $aggregations['Comment'] = $interactionType['doc_count'];
                    }
                    if ($interactionType['key'] === 'Verification') {
                        $aggregations['Verification'] = $interactionType['doc_count'] * 2;
                    }
                    if ($interactionType['key'] === 'Added_Patchset') {
                        $aggregations['Added_Patchset'] = $interactionType['doc_count'] * 5;
                    }
                    if ($interactionType['key'] === 'Code_Review') {
                        $aggregations['Code_Review'] = $interactionType['doc_count'] * 2;
                    }
                    if ($interactionType['key'] === 'Merge') {
                        $aggregations['Merge'] = $interactionType['doc_count'] * 20;
                    }
                    if ($interactionType['key'] === 'Abandoned_Patch') {
                        $aggregations['Abandoned_Patch'] = $interactionType['doc_count'] * 3;
                    }
                }
                $aggregations['date'] = $this->fixTime($frame['key_as_string']);
                $this->chartData[] = $aggregations;
            }
        }
        return $this->chartData;
    }
}
