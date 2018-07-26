<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http\Controllers;

use App\Elastic\Connection;
use App\Elastic\ElasticSearch;
use App\Http\Controllers\Graph\Forge\Full as FullForge;
use App\Http\Controllers\Graph\Gerrit\Full;
use Illuminate\Http\Request;

/**
 * Class SearchController.
 */
class SearchController extends Controller
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * SearchController constructor.
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }
    /**
     * Show the index screen to the user.
     *
     * @return string
     */
    public function getIndex() : string
    {
        $reviewGraph = new Full($this->connection);
        $issueGraph = new FullForge($this->connection);
        $this->view->assign('openReviews', $reviewGraph->render());
        $this->view->assign('openIssues', $issueGraph->render());
        $graphConfig = [
            'Forge' => [
                'OpenVsClosed' => [
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#5abc55',
                        'lineThickness' => 2,
                        'showBallon' => true,
                        'valueField' => 'closed',
                        'title' => 'Closed Tickes',
                        'fillAlphas' => 1,
                        'bullet' => 'round',
                        'bulletSize' => 6
                    ],
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#dd123d',
                        'lineThickness' => 2,
                        'showBallon' => true,
                        'valueField' => 'open',
                        'title' => 'Opened Tickes',
                        'fillAlphas' => 0.5,
                        'bullet' => 'round',
                        'bulletSize' => 6
                    ],
                ],
                'Burndown' => [
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#f49800',
                        'lineThickness' => 2,
                        'showBallon' => true,
                        'valueField' => 'diff',
                        'title' => 'Reviews',
                        'fillAlphas' => 0.5,
                        'bullet' => 'round',
                        'bulletSize' => 6
                    ]
                ],
                'TrackerTypes' => [
                    [
                        'balloonText' => '<b>[[title]]</b><br><span style=\'font-size:14px\'>[[category]]: <b>[[value]]</b></span>',
                        'fillAlphas' => 0.8,
                        'labelText' => '[[value]]',
                        'lineAlpha' => 0.3,
                        'title' => 'New',
                        'type' => 'column',
                        'lineColor' => '#fb7e7e',
                        'valueField' => 'New'
                    ],
                    [
                        'balloonText' => '<b>[[title]]</b><br><span style=\'font-size:14px\'>[[category]]: <b>[[value]]</b></span>',
                        'fillAlphas' => 0.8,
                        'labelText' => '[[value]]',
                        'lineAlpha' => 0.3,
                        'title' => 'Accepted',
                        'type' => 'column',
                        'lineColor' => '#fbee99',
                        'valueField' => 'Accepted'
                    ],
                    [
                        'balloonText' => '<b>[[title]]</b><br><span style=\'font-size:14px\'>[[category]]: <b>[[value]]</b></span>',
                        'fillAlphas' => 0.8,
                        'labelText' => '[[value]]',
                        'lineAlpha' => 0.3,
                        'title' => 'Needs Feedback',
                        'type' => 'column',
                        'lineColor' => '#FBC3C3',
                        'valueField' => 'NeedsFeedback'
                    ],
                    [
                        'balloonText' => '<b>[[title]]</b><br><span style=\'font-size:14px\'>[[category]]: <b>[[value]]</b></span>',
                        'fillAlphas' => 0.8,
                        'labelText' => '[[value]]',
                        'lineAlpha' => 0.3,
                        'title' => 'In Progress',
                        'type' => 'column',
                        'lineColor' => '#A9E2FF',
                        'valueField' => 'InProgress'
                    ],
                    [
                        'balloonText' => '<b>[[title]]</b><br><span style=\'font-size:14px\'>[[category]]: <b>[[value]]</b></span>',
                        'fillAlphas' => 0.8,
                        'labelText' => '[[value]]',
                        'lineAlpha' => 0.3,
                        'title' => 'On Hold',
                        'type' => 'column',
                        'lineColor' => '#D1F412',
                        'valueField' => 'OnHold'
                    ],
                    [
                        'balloonText' => '<b>[[title]]</b><br><span style=\'font-size:14px\'>[[category]]: <b>[[value]]</b></span>',
                        'fillAlphas' => 0.8,
                        'labelText' => '[[value]]',
                        'lineAlpha' => 0.3,
                        'title' => 'Under Review',
                        'type' => 'column',
                        'lineColor' => '#aeff91',
                        'valueField' => 'UnderReview'
                    ],
                    [
                        'balloonText' => '<b>[[title]]</b><br><span style=\'font-size:14px\'>[[category]]: <b>[[value]]</b></span>',
                        'fillAlphas' => 0.8,
                        'labelText' => '[[value]]',
                        'lineAlpha' => 0.3,
                        'title' => 'ReadyForDeployment',
                        'type' => 'column',
                        'lineColor' => '#ff9900',
                        'valueField' => 'ReadyForDeployment'
                    ],
                ]
            ],
            'Gerrit' => [
                'OpenReviews' => [
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#538bb3',
                        'lineThickness' => 2,
                        'showBallon' => true,
                        'valueField' => 'open',
                        'title' => 'Reviews',
                        'fillAlphas' => 0.5,
                        'bullet' => 'round',
                        'bulletSize' => 6
                    ]
                ],
                'Burndown' => [
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#538bb3',
                        'lineThickness' => 2,
                        'showBallon' => true,
                        'valueField' => 'diff',
                        'title' => 'Reviews',
                        'fillAlphas' => 0.5,
                        'bullet' => 'round',
                        'bulletSize' => 6
                    ]
                ],
                'Activity' => [
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#f4f4f4',
                        'valueField' => 'Comment',
                        'title' => 'Comments',
                        'fillAlphas' => 1,
                    ],
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#b9b9b9',
                        'valueField' => 'Verification',
                        'title' => 'Verification',
                        'fillAlphas' => 1,
                    ],
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#b9b9b9',
                        'valueField' => 'Code_Review',
                        'title' => 'Code Review',
                        'fillAlphas' => 1,
                    ],
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#538bb3',
                        'valueField' => 'Added_Patchset',
                        'title' => 'Added Patchset',
                        'fillAlphas' => 1,
                    ],
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#dd123d',
                        'valueField' => 'Abandoned_Patch',
                        'title' => 'Abandoned Patch',
                        'fillAlphas' => 1,
                    ],
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#75a75a',
                        'valueField' => 'Merge',
                        'title' => 'Merge',
                        'fillAlphas' => 1,
                    ],
                ]
            ],
            'Generic' => [
                'Velocity' => [
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#dd123d',
                        'lineThickness' => 2,
                        'showBallon' => true,
                        'valueField' => 'open',
                        'title' => 'Opened Tickes',
                        'fillAlphas' => 0,
                        'id' => 'fromGraph'
                    ],
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#5abc55',
                        'lineThickness' => 2,
                        'showBallon' => true,
                        'valueField' => 'closed',
                        'title' => 'Closed Tickes',
                        'fillAlphas' => 0.2,
                        'fillToGraph' => 'fromGraph'
                    ],
                ],
                'Period' => [
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#eeeeee',
                        'lineThickness' => 2,
                        'showBallon' => true,
                        'valueField' => 'previous2',
                        'title' => date('Y', mktime(10, 0, 0, 2, 1, (int)date('Y')-2)),
                        'fillAlphas' => 1,
                        'bullet' => 'round',
                        'bulletSize' => 6
                    ],
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#dddddd',
                        'lineThickness' => 2,
                        'showBallon' => true,
                        'valueField' => 'previous',
                        'title' => date('Y', mktime(10, 0, 0, 2, 1, (int)date('Y')-1)),
                        'fillAlphas' => 0.5,
                        'bullet' => 'round',
                        'bulletSize' => 6
                    ],
                    [
                        'lineAlpha' => 1,
                        'lineColor' => '#f49800',
                        'lineThickness' => 2,
                        'showBallon' => true,
                        'valueField' => 'current',
                        'title' => date('Y'),
                        'fillAlphas' => 0.5,
                        'bullet' => 'round',
                        'bulletSize' => 6
                    ],
                ]
            ]
        ];
        $this->view->assign('graphConfig', $graphConfig);

        return $this->view->render('index');
    }

    /**
     * @param Request $request
     *
     * @return string
     *
     * @throws \Exception
     */
    public function getSearch(Request $request) : string
    {
        $query = $request->input('query');
        $searchClosed = false;
        if ($request->has('searchClosed') && (int)$request->input('searchClosed') === 1) {
            $searchClosed = true;
        }
        $results = [];
        $this->view->assign('query', $query);
        $this->view->assign('searchClosed', $searchClosed);
        if ($this->checkIfQueryIsIssue($query)) {
            $issue = $this->findIssue($query);
            if (array_key_exists('error', $issue)) {
                $this->view->assign('ErrorMessage', $issue['error']);
            } else {
                $results = $this->findDupes($issue['id'], $searchClosed);
            }
        } else {
            $results = $this->findByQuery($query, $searchClosed);
            $this->view->assignMultiple([
                'mode' => 'query',
            ]);
        }
        $this->view->assign('result', $results);
        if (array_key_exists('filters', $_GET)) {
            $this->view->assign('filters', $_GET['filters']);
        }

        return $this->view->render('search');
    }

    /**
     * @param $query
     *
     * @return bool
     */
    protected function checkIfQueryIsIssue($query) : bool
    {
        $result = false;
        $pattern = '(^[0-9]{4,6}$)';
        preg_match($pattern, $query, $matches);
        if (count($matches) > 0) {
            $result = true;
        }

        return $result;
    }

    /**
     * @param $singleScore
     * @param $maxScore
     *
     * @return string
     */
    protected function calculateScoring($singleScore, $maxScore) : string
    {
        return number_format(($singleScore * 100) / $maxScore);
    }

    /**
     * @param int  $issueId
     * @param bool $searchInClosedIssues
     *
     * @return array
     *
     * @throws \Exception
     */
    private function findDupes($issueId, $searchInClosedIssues = false) : array
    {
        $search = new ElasticSearch($this->connection);
        $query = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'more_like_this' => [
                                'fields' => [
                                    'subject',
                                    'description',
                                ],
                                'ids' => [
                                    $issueId,
                                ],
                                'min_term_freq' => 2,
                                'max_query_terms' => 25,
                                'minimum_should_match' => '50%',
                            ],
                        ],
                    ],
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
                    ],
                ],
            ],
        ];
        if ($searchInClosedIssues) {
            unset($query['query']['bool']['must_not']);
        }

        return $search->doSearch($query);
    }

    /**
     * @param int $issueId
     *
     * @return array|string
     */
    private function findIssue($issueId)
    {
        $index = $this->connection->getElasticIndex();
        $forger = $index->getType('issue');
        try {
            $res = $forger->getDocument($issueId);
            // Need to use an ugly hack
            // funny noone found that one earlier
            // \TYPO3\Flow\var_dump($res);
            $data = $res->getData();
            $this->view->assign('issue', [
                'hit' => [
                    '_source' => $data,
                ],
            ]);
        } catch (\Exception $e) {
            $data = [
                'error' => 'Issue #' . $issueId . ' was not found.',
            ];
        }

        return $data;
    }

    /**
     * @param string $searchTerms
     * @param bool   $searchInClosedIssues
     *
     * @return array
     *
     * @throws \Exception
     */
    private function findByQuery($searchTerms, $searchInClosedIssues = false) : array
    {
        $searchTerms = $this->escape($searchTerms);
        $search = new ElasticSearch($this->connection);
        $query = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'query_string' => [
                                'query' => $searchTerms,
                            ],
                        ],
                    ],
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
                    ],
                ],
            ],
        ];
        if ($searchInClosedIssues) {
            unset($query['query']['bool']['must_not']);
        }

        return $search->doSearch($query);
    }

    /**
     * Escape a value for special query characters such as ':', '(', ')', '*', '?', etc.
     * NOTE: inside a phrase fewer characters need escaped, use {@link Apache_Solr_Service::escapePhrase()} instead.
     *
     * @param string $value
     *
     * @return string
     */
    public function escape($value) : string
    {
        //list taken from http://lucene.apache.org/java/docs/queryparsersyntax.html#Escaping%20Special%20Characters
        $pattern = '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\*|\?|:|\\\)/';
        $replace = '\\\$1';
        $escapedString = preg_replace($pattern, $replace, $value);
        $escapedString = str_replace('/', '\/', $escapedString);

        return $escapedString;
    }
}
