<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;
use Illuminate\Http\Response;
use Elastica;
use Debugbar;

class GerritController extends Controller
{
    /**
     * @var string
     */
    protected $context;

    private $perPage = 25;

    private $currentPage = 1;

    private $totalHits;

    protected $dateInterval = 'month';

    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        date_default_timezone_set('UTC');
        $this->connection = $connection;
        if (array_key_exists('page', $_GET)) {
            $this->currentPage = (int)$_GET['page'];
        }
    }

    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getStatus()
    {
        Debugbar::startMeasure(__FUNCTION__, __FUNCTION__);
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'NEW',
                                ],
                            ],

                        ]
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED'
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip'
                            ],
                        ]
                    ]
                ]
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $this->queryAggregations()
        ];
        $this->search($fullRequest);
        Debugbar::stopMeasure(__FUNCTION__);
        return $this->view->render('status');
    }

    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getFiles()
    {
        Debugbar::startMeasure(__FUNCTION__, __FUNCTION__);
        if (array_key_exists('query', $_GET)) {
            $searchClosed = false;
            if (array_key_exists('searchClosed', $_GET) && $_GET['searchClosed'] === 'true') {
                $searchClosed = true;
            }
            $fullRequest = [
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'terms' => [
                                    'files' => [
                                        htmlspecialchars($_GET['query']),
                                    ],
                                ],

                            ]
                        ],
                        'must_not' => [
                            [
                                'terms' => [
                                    'reviewstatus' => [
                                        'MERGED',
                                        'ABANDONED'
                                    ],
                                ],

                            ],
    //                        [
    //                            'term' => [
    //                                'subject' => 'wip'
    //                            ],
    //                        ]
                        ]
                    ]
                ],
                'sort' => $this->querySort(),
                'size' => $this->perPage,
                'from' => $this->currentPage * $this->perPage - $this->perPage,
                'aggregations' => $this->queryAggregations()
            ];
            if ($searchClosed) {
                unset($fullRequest['query']['bool']['must_not']);
            }
            $this->search($fullRequest);
            $this->view->assign('query', htmlspecialchars($_GET['query']));
        }
        Debugbar::stopMeasure(__FUNCTION__);
        return $this->view->render('files');
    }

    /**
     * Show the Reviews ready to merge.
     *
     * @return Response
     */
    public function getMergeReady()
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'positive_reviews' => [
                                    'gte' => 1
                                ]
                            ],
                        ],
                        [
                            'range' => [
                                'positive_verifications' => [
                                    'gte' => 1
                                ]
                            ],
                        ]
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED'
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip'
                            ],
                        ]
                    ]
                ]
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $this->queryAggregations()
        ];
        $this->search($fullRequest);
        return $this->view->render('MergeReady');
    }

    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getMissesVerification()
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'positive_reviews' => [
                                    'gte' => 1
                                ]
                            ],
                        ],
                        [
                            'range' => [
                                'positive_verifications' => [
                                    'lt' => 1
                                ]
                            ],
                        ]
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED'
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip'
                            ],
                        ]
                    ]
                ]
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $this->queryAggregations()
        ];
        $this->search($fullRequest);
        return $this->view->render('missesVerification');
    }

    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getMissesReview()
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'positive_reviews' => [
                                    'lt' => 1
                                ]
                            ],
                        ],
                        [
                            'range' => [
                                'positive_verifications' => [
                                    'gte' => 1
                                ]
                            ],
                        ]
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED'
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip'
                            ],
                        ]
                    ]
                ]
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $this->queryAggregations()
        ];
        $this->search($fullRequest);
        return $this->view->render('MissesReview');
    }

    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getRebase()
    {
        $aggs = $this->queryAggregations();
        unset($aggs['Mergeable'], $aggs['Patchsets'], $aggs['Releases'], $aggs['Topic']);
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'term' => [
                                'mergeable' => 'no'
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED'
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip'
                            ],
                        ]
                    ]
                ]
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $aggs
        ];
        $this->search($fullRequest);
        return $this->view->render('Rebase');
    }

    /**
     * @return string
     */
    public function getWorkInProgress()
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'term' => [
                                'subject' => 'wip'
                            ],
                        ]
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED'
                                ],
                            ],

                        ]
                    ]
                ]
            ],
            'sort' => [
                'updated_on' => 'asc',
                'insertions' => 'asc',
                'deletions' => 'asc',
                'patchsets' => 'desc'
            ],
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => [
                'Releases' => [
                    'terms' => [
                        'field' => 'releases',
                        'size' => 0
                    ]
                ],
                'Last updated' => [
                    'date_histogram' => [
                        'field' => 'updated_on',
                        'interval' => $this->dateInterval,
                        'format' => 'MMMM yyyy'
                    ],
                ]
            ]
        ];
        $this->search($fullRequest);
        return $this->view->render('WorkInProgress');
    }

    /**
     * @param array $fullRequest
     */
    protected function search(array $fullRequest)
    {
        $usedFilters = $this->addFilters();
        if (count($usedFilters) > 0) {
            $fullRequest['post_filter'] = $usedFilters;
        }
        $query = new Elastica\Query($fullRequest);
        $resultSet = $this->connection->getElasticIndex()->search($query);
        $this->totalHits = $resultSet->getTotalHits();
        if ((int) $this->totalHits <= (int)($this->currentPage * $this->perPage)) {
            $this->view->assign('endingAtItem', (int)$this->totalHits);
        } else {
            $this->view->assign('endingAtItem', $this->currentPage * $this->perPage);
        }
        $this->view->assignMultiple([
            'results' => $resultSet->getResults(),
            'pagesToLinkTo' => $this->getPages(),
            'currentPage' => $this->currentPage,
            'prev' => $this->currentPage - 1,
            'next' => $this->currentPage < ceil($this->totalHits / $this->perPage) ? $this->currentPage + 1 : 0,
            'totalResults' => $this->totalHits,
            'startingAtItem' => ($this->currentPage * $this->perPage) - ($this->perPage - 1),
            'aggregations' => $resultSet->getAggregations(),
            'context' => $this->context
        ]);
        if (array_key_exists('filters', $_GET)) {
            $this->view->assign('filters', $_GET['filters']);
        }
    }

    /**
     * Makes the date string from elastic JS compatible
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
     * @return array
     */
    protected function addFilters()
    {
        $query = new Elastica\Query();
        $filters = new Elastica\Query\BoolQuery();

        if (!array_key_exists('filters', $_GET)) {
            return $filters;
        }
        foreach ($_GET['filters'] as $key => $filterValue) {
            $filterCatCount = 0;
            $filterPart = new Elastica\Query\BoolQuery();
            foreach ($filterValue as $term => $enabled) {
                if ($enabled === 'true') {
                    $term = str_replace('_', ' ', $term);
                    $filter = new Elastica\Query\Term();
                    if ($key === 'tracker'
                        || $key === 'project'
                        || $key === 'category'
                        || $key === 'status'
                        || $key === 'author'
                        || $key === 'priority'
                        || $key === 'fixed_version'
                    ) {
                        $filter->setTerm($key . '.name', $term);
                    } else {
                        $filter->setTerm($key, $term);
                    }
                    $filterPart->addShould($filter);
                    $filterCatCount++;
                }
            }
            if ($filterCatCount > 0) {
                $filters->addMust($filterPart);
            }
        }
        $query->setQuery($filters);
        return $query;
    }

    /**
     * @return array
     */
    protected function queryAggregations()
    {
        return [
            'Releases' => [
                'terms' => [
                    'field' => 'releases',
                    'size' => 0
                ]
            ],
            'Affected Files' => [
                'terms' => [
                    'field' => 'affected_files'
                ]
            ],
            'Topic' => [
                'terms' => [
                    'field' => 'topic',
                    'size' => 0,
                    'order' => [
                        '_term' => 'asc'
                    ]
                ]
            ],
            'Patchsets' => [
                'terms' => [
                    'field' => 'patchsets'
                ]
            ],
            'Mergeable' => [
                'terms' => [
                    'field' => 'mergeable'
                ]
            ],
            'Owner' => [
                'terms' => [
                    'field' => 'owner',
                    'size' => 10000
                ],
            ],
        ];
    }

    /**
     * Query sorting
     *
     * @return array
     */
    protected function querySort()
    {
        return [
            'updated_on' => 'desc',
            'insertions' => 'asc',
            'deletions' => 'asc',
            'patchsets' => 'desc'
        ];
    }

    /**
     * Returns an array of linkable pages
     *
     * @return array
     */
    protected function getPages()
    {
        $numPages = ceil($this->totalHits / $this->perPage);
        $i = 0;
        /**
         *
         */
        $maxPages = $numPages;
        if ($numPages > 15 && $this->currentPage <= 7) {
            $numPages = 15;
        }
        if ($this->currentPage > 7) {
            $i = $this->currentPage - 7;
            $numPages = $this->currentPage + 6;
        }
        if ($numPages > $maxPages) {
            $numPages = $maxPages;
            $i = $maxPages - 15;
        }

        if ($i < 0) {
            $i = 0;
        }

        $out = array();
        while ($i < $numPages) {
            $out[(int)$i] = ($i + 1);
            $i++;
        }

        return $out;
    }
}
