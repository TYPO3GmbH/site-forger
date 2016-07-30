<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;
use App\Elastic\ElasticSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SearchController extends Controller
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
        return $this->view->render('index');
    }

    /**
     * @param Request $request
     *
     * @return string
     * @throws \Exception
     */
    public function getSearch(Request $request)
    {
        $query = $request->input('query');
        $searchClosed = false;
        if ($request->has('searchClosed')) {
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
                'mode' => 'query'
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
    protected function checkIfQueryIsIssue($query)
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
    protected function calculateScoring($singleScore, $maxScore)
    {
        return number_format(($singleScore * 100) / $maxScore);
    }

    /**
     * @param int  $issueId
     * @param bool $searchInClosedIssues
     *
     * @return \Elastica\ResultSet
     * @throws \Exception
     */
    private function findDupes($issueId, $searchInClosedIssues = false)
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
                                    'description'
                                ],
                                'ids' => [
                                    $issueId
                                ],
                                'min_term_freq' => 2,
                                'max_query_terms' => 25,
                                'minimum_should_match' => '50%',
                            ]
                        ]
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'status.name' => [
                                    'Closed',
                                    'Rejected',
                                    'Resolved'
                                ]
                            ],
                        ]
                    ]
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
                    '_source' => $data
                ]
            ]);
        } catch (\Exception $e) {
            $data = array(
                'error' => 'Issue #' . $issueId . ' was not found.'
            );
        }
        return $data;
    }

    /**
     * @param string $searchTerms
     * @param bool   $searchInClosedIssues
     *
     * @return \Elastica\ResultSet
     * @throws \Exception
     */
    private function findByQuery($searchTerms, $searchInClosedIssues = false)
    {
        $searchTerms = $this->escape($searchTerms);
        $search = new ElasticSearch($this->connection);
        $query = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'query_string' => [
                                'query' => $searchTerms
                            ]
                        ]
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'status.name' => [
                                    'Closed',
                                    'Rejected',
                                    'Resolved'
                                ]
                            ],
                        ]
                    ]
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
     * NOTE: inside a phrase fewer characters need escaped, use {@link Apache_Solr_Service::escapePhrase()} instead
     *
     * @param string $value
     *
     * @return string
     */
    public function escape($value)
    {
        //list taken from http://lucene.apache.org/java/docs/queryparsersyntax.html#Escaping%20Special%20Characters
        $pattern = '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\*|\?|:|\\\)/';
        $replace = '\\\$1';
        $escapedString = preg_replace($pattern, $replace, $value);
        $escapedString = str_replace('/', '\/', $escapedString);

        return $escapedString;
    }
}
