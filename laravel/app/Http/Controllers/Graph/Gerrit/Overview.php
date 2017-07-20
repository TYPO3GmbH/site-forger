<?php

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
        $this->chartData = [
            'chartData' => $this->gerritOpenVsClosedAction(),
            'guides' => $this->getGuides(),
        ];
        return $this->chartData;
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
                            'query_string' => [
                                'default_field' => 'subject',
                                'query' => 'WIP',
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
                            'query_string' => [
                                'default_field' => 'subject',
                                'query' => 'WIP',
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
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $row[$this->fixTime($frame['key_as_string'])] = $frame['doc_count'];
            }
        }

        return $row;
    }
}
