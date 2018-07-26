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
 * Class Overview.
 */
class Overview extends AbstractGraph
{
    /**
     * @var
     */
    protected $weirdTimeThingy;

    /**
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData() : array
    {
        $this->weirdTimeThingy = strftime('%Y/%m/%d 00:00:00', time() - (((365 / 2) - 31) * 24 * 60 * 60));
        return $this->chartData = $this->gerritOpenVsClosedAction();
    }

    /**********************************************
     * GERRIT RELATED THINGS
     ***********************************************/
    /**
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function gerritOpenVsClosedAction() : array
    {
        $row = [];
        $opened = $this->gerritGetOpened();
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'ABANDONED',
                                    'MERGED',
                                ],
                            ],
                        ],
                        [
                            'range' => [
                                'updated_on' => [
                                    'gte' => $this->weirdTimeThingy,
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewtags' => [
                                    'WIP'
                                ]
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
                        'interval' => 'day',
                    ],
                    'aggregations' => [
                        'issue_type' => [
                            'terms' => [
                                'field' => 'reviewstatus',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest, self::DOCUMENT_TYPE_REVIEW);
        $data = $resultSet->getAggregations();
        $i = 0;
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $date = $this->fixTime($frame['key_as_string']);
                $row[$i]['date'] = $date;
                if (is_array($frame['issue_type']['buckets'])) {
                    foreach ($frame['issue_type']['buckets'] as $issueType) {
                        $row[$i][$issueType['key']] = $issueType['doc_count'];
                    }
                    if (isset($opened[$date])) {
                        $row[$i]['NEW'] = $opened[$date];
                    }
                    if (!array_key_exists('ABANDONED', $row[$i])) {
                        $row[$i]['ABANDONED'] = 0;
                    }
                    if (!array_key_exists('NEW', $row[$i])) {
                        $row[$i]['NEW'] = 0;
                    }
                    if (!array_key_exists('MERGED', $row[$i])) {
                        $row[$i]['MERGED'] = 0;
                    }
                    ++$i;
                }
            }
        }

        return $row;
    }

    /**
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function gerritGetOpened() : array
    {
        $row = [];

        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'created_on' => [
                                    'gte' => $this->weirdTimeThingy,
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewtags' => [
                                    'WIP'
                                ]
                            ],
                        ],
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'created_on',
                        'interval' => 'day',
                    ],
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest, self::DOCUMENT_TYPE_REVIEW);
        $data = $resultSet->getAggregations();
        if (\is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $row[$this->fixTime($frame['key_as_string'])] = $frame['doc_count'];
            }
        }
        return $row;
    }
}
