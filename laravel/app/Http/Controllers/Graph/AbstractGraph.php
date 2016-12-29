<?php

namespace App\Http\Controllers\Graph;

use App\Elastic\Connection;
use Elastica\ResultSet;

/**
 * Class AbstractGraph.
 */
class AbstractGraph
{
    const DOCUMENT_TYPE_ISSUE = 'issue';
    const DOCUMENT_TYPE_REVIEW = 'review';

    /**
     * @var array
     */
    protected $chartData = [];

    /**
     * @var string
     */
    protected $dateInterval = 'week';

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * Calculated Chart data from results
     *
     * @var array
     */
    protected $result = [];

    /**
     * AbstractGraph constructor.
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Makes the date string from elastic JS compatible.
     *
     * @param string $in
     *
     * @return string
     */
    protected function fixTime($in)
    {
        $date = substr($in, 0, 10);

        return str_replace('/', '-', $date);
    }

    /**
     * @return string
     */
    public function render()
    {
        $this->getData();

        return json_encode($this->chartData);
    }

    /**
     * @return array
     */
    protected function getData()
    {
        return [];
    }

    /**
     * @return array
     */
    protected function getGuides()
    {
        return [];
    }

    /**
     * @param array  $fullRequest
     * @param string $type
     *
     * @return ResultSet
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function doSearch(array $fullRequest, $type = self::DOCUMENT_TYPE_ISSUE) : ResultSet
    {
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType($type);

        return $search->search();
    }

    /**
     * Get's opened reviews over time.
     *
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getOpenedReviews()
    {
        $opened = [];
        $fullRequest = [
            'query' => [
                'match_all' => [],
            ],
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'created_on',
                        'interval' => $this->dateInterval,
                    ],
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest);
        $data = $resultSet->getAggregations();
        $openSum = 0;
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $openSum += $frame['doc_count'];
                $date = $this->fixTime($frame['key_as_string']);
                $opened[$date]['open'] = $openSum;
            }
        }

        return $opened;
    }

    /**
     * Get's closed reviews over time.
     *
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getClosedReviews()
    {
        $closed = [];
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        'terms' => [
                            'status.name' => [
                                'Closed',
                                'Resolved',
                                'Rejected',
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
                        'interval' => $this->dateInterval,
                    ],
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest);
        $data = $resultSet->getAggregations();
        $closeSum = 0;
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $closeSum += $frame['doc_count'];
                $date = $this->fixTime($frame['key_as_string']);
                $closed[$date]['closed'] = $closeSum;
            }
        }

        return $closed;
    }

    /**
     * Get's opened reviews over time.
     *
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getOpenedReviewsWithoutWIP()
    {
        $opened = [];
        $fullRequest = [
            'query' => [
                'bool' => [
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
                        'interval' => $this->dateInterval,
                    ],
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest, self::DOCUMENT_TYPE_REVIEW);
        $data = $resultSet->getAggregations();
        $openSum = 0;
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $openSum += $frame['doc_count'];
                $date = $this->fixTime($frame['key_as_string']);
                $opened[$date]['open'] = $openSum;
            }
        }

        return $opened;
    }

    /**
     * Get's closed reviews over time.
     *
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getClosedReviewsWithoutWIP()
    {
        $closed = [];
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        'terms' => [
                            'reviewstatus' => [
                                'MERGED',
                                'ABANDONED',
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
                        'interval' => $this->dateInterval,
                    ],
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest, self::DOCUMENT_TYPE_REVIEW);
        $data = $resultSet->getAggregations();
        $closeSum = 0;
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $closeSum += $frame['doc_count'];
                $date = $this->fixTime($frame['key_as_string']);
                $closed[$date]['closed'] = $closeSum;
            }
        }

        return $closed;
    }

    /**
     * Gets the cumulative amount of documents for a given year
     *
     * @param int    $year
     * @param string $key
     * @param string $type
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getCumulativeTicketsToDate(int $year, string $key, $type = self::DOCUMENT_TYPE_ISSUE)
    {
        $terms = [
            'status.name' => [
                'Closed',
                'Rejected',
                'Resolved',
            ],
        ];
        if ($type === self::DOCUMENT_TYPE_REVIEW) {
            $terms = [
                'reviewstatus' => [
                    'ABANDONED',
                    'MERGED',
                ],
            ];
        }
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'terms' => $terms,
                        ],
                        [
                            'range' => [
                                'updated_on' => [
                                    'gte' => $year,
                                    'lt' => $year + 1,
                                    'format' => 'yyyy'
                                ],
                            ],
                        ]
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'updated_on',
                        'interval' => $this->dateInterval,
                    ],
                ],
            ],
        ];
        $resultSet = $this->doSearch($fullRequest, $type);
        $data = $resultSet->getAggregations();
        $openSum = 0;
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $openSum += $frame['doc_count'];
                switch ($key) {
                    case 'previous':
                        $date = str_replace($year, $year + 1, $this->fixTime($frame['key_as_string']));
                        break;
                    case 'previous2':
                        $date = str_replace($year, $year + 2, $this->fixTime($frame['key_as_string']));
                        break;
                    default:
                        $date = $this->fixTime($frame['key_as_string']);
                }
                $this->result[$date][$key] = $openSum;
                $this->result[$date]['date'] = $date;
            }
        }
    }
}
