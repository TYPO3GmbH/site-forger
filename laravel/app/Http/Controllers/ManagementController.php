<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http\Controllers;

use App\Elastic\Connection;
use App\Elastic\TrackerFilters;

/**
 * Class ManagementController.
 */
class ManagementController extends Controller
{
    /**
     * @var Connection
     */
    private $connection;

    private $coreMembers = [
        'Alexander Schnitzler',
        'Andreas Fernandez',
        'Anja Leichsenring',
        'Arno Schoon',
        'Benjamin Franzke',
        'Benni Mack',
        'Claus Due',
        'Daniel Goerz',
        'Daniel Sattler',
        'Frank Naegler',
        'Georg Ringer',
        'Josef Glatz',
        'Manuel Selbach',
        'Oliver Hader',
        'Ralf Zimmermann',
        'Riccardo De Contardi',
        'Richard Haeser',
        'Susanne Moog',
        'Tobias Kretschmann',
        'Tymoteusz Motylewski',
    ];

    private $timeFrame = '2017/01/01 00:00:00';

    /**
     * ManagementController constructor.
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
        sort($this->coreMembers);
    }

    /**
     * Show the index screen to the user.
     *
     * @return string
     *
     * @throws \Elastica\Exception\InvalidException
     */
    public function getIndex() : string
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
                $months = array_fill(0, 12, []);
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
     * Show the index screen to the user.
     *
     * @return string
     *
     * @throws \Elastica\Exception\InvalidException
     */
    public function getInteractions() : string
    {
        if (isset($_GET['taggles'])) {
            $this->coreMembers = $this->sanitizeTaggles($_GET['taggles']);
            sort($this->coreMembers);
        }
        $out = [];
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'interacted_on' => [
                                    'gte' => $this->timeFrame
                                ]
                            ]
                        ],
                        [
                            'terms' => [
                                'fullname' => $this->coreMembers,
                            ]
                        ]
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'month' => [
                    'date_histogram' => [
                        'field' => 'interacted_on',
                        'interval' => 'month',
                    ],
                    'aggregations' => [
                        'member' => [
                            'terms' => [
                                'field' => 'fullname',
                                'size' => 30,
                            ],
                            'aggregations' => [
                                'interaction_type' => [
                                    'terms' => [
                                        'field' => 'interaction_type',
                                    ],
                                ],
                            ],
                        ],
                    ]
                ],
            ],
        ];
        $search = $this->connection->getElasticIndex()->createSearch($fullRequest);
        $search->addType('interactions');
        $resultSet = $search->search();
        $aggregations = $resultSet->getAggregations();
        foreach ($aggregations as $aggregation) {
            foreach ($aggregation['buckets'] as $bucket) {
                $monthParts = explode('/', $bucket['key_as_string']);
                $dateIdent = $monthParts[0] . '/' . $monthParts[1];
                foreach ($this->coreMembers as $coreMember) {
                    $out[$dateIdent][$coreMember] = [];
                }
                foreach ($bucket['member']['buckets'] as $member) {
                    foreach ($member['interaction_type']['buckets'] as $interactionType) {
                        $out[$dateIdent][$member['key']][$interactionType['key']] = $interactionType['doc_count'];
                    }
                }
            }
        }
        krsort($out);
        $this->view->assign('aggs', $out);
        $this->view->assign('coreTeamMembers', $this->coreMembers);

        return $this->view->render('interactions');
    }

    private function sanitizeTaggles(array $taggles)
    {
        $sanitizedArray = [];
        foreach ($taggles as $index => $taggle) {
            if (!is_string($taggle)) {
                throw new \InvalidArgumentException('Taggles need to be strings');
            }
            $result = preg_replace("/[^[:alnum:][:space:]-\.']/u", '', html_entity_decode($taggle));
            if (trim($result) !== '') {
                $sanitizedArray[$index] = $result;
            }
        }
        return $sanitizedArray;
    }
}
