<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;
use App\Elastic\TrackerFilters;
use Illuminate\Http\Response;

class ManagementController extends Controller
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }
    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getIndex()
    {
        $trackerFilters = new TrackerFilters();
        $this->view->assign('trackerFilters', $trackerFilters);
        $out = [];
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must_not' => [
                        [
                            'terms' => [
                                'status.name' => [
                                    'Closed',
                                    'Rejected',
                                    'Resolved',
                                ],
                            ],
                        ],
                        [
                            'terms' => [
                                'project.id' => [
                                    '78',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'size' => 0,
            'filter' => $this->queryFilters(),
            'aggregations' => [
                'year' => [
                    'date_histogram' => [
                        'field' => 'updated_on',
                        'interval' => 'year',
                    ],
                    'aggregations' => [
                        'month' => [
                            'date_histogram' => [
                                'field' => 'updated_on',
                                'interval' => 'month',
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
                ],
            ],
        ];
        if ($trackerFilters->isFeature()) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = 'Feature';
        }
        if ($trackerFilters->isTask()) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = 'Task';
        }
        if ($trackerFilters->isBug()) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = 'Bug';
        }
        if ($trackerFilters->isEpic()) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = 'Epic';
        }
        if ($trackerFilters->isStory()) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = 'Story';
        }
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType('issue');
        $resultSet = $search->search();
        foreach ($resultSet->getAggregations() as $aggregation) {
            foreach ($aggregation['buckets'] as $bucket) {
                $dateParts = explode('/', $bucket['key_as_string']);
                $yearName = $dateParts[0];
                // prefill array with 12 index, for 12 month
                $months = array_fill(0, 12, array());
                foreach ($bucket['month']['buckets'] as $month) {
                    $dateParts = explode('/', $month['key_as_string']);
                    $monthName = $dateParts[1];
                    $stati = [];
                    foreach ($month['status']['buckets'] as $status) {
                        $stati[] = [
                            'name' => $status['key'],
                            'total' => $status['doc_count'],
                        ];
                    }
                    $monthIndex = (int) $monthName - 1;
                    $months[$monthIndex] = [
                        'month' => $monthName,
                        'total' => $month['doc_count'],
                        'stati' => $stati,
                    ];
                }
                $out[] = [
                    'year' => $yearName,
                    'total' => $bucket['doc_count'],
                    'months' => $months,
                ];
            }
        }
        $this->view->assign('aggs', $out);

        return $this->view->render('index');
    }

    /**
     * @return array
     */
    protected function queryFilters()
    {
        $filters = [
            'bool' => [
                'must' => [
                    'type' => [
                        'value' => 'issue',
                    ],
                ],
                'must_not' => [
//					'term' => [
//						'subject' => 'wip'
//					]
                ],
            ],
        ];

        return $filters;
    }
}
