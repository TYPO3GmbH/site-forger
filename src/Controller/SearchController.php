<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use Elastica\Aggregation\Terms;
use Elastica\Query;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use T3G\ElasticUtility\ElasticUtility;

/**
 * @Route("/search", name="search_")
 */
class SearchController extends AbstractController
{
    protected $perPage = 25;

    protected $currentPage = 1;

    protected $totalHits;

    public function __construct()
    {
        if (array_key_exists('page', $_GET)) {
            $this->currentPage = (int) $_GET['page'];
        }
    }

    /**
     * @Route(name="search")
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function searchAction(Request $request): Response
    {
        $viewData = [];
        $query = $request->get('query');
        $searchClosed = false;
        if ($request->get('searchClosed') && (int)$request->get('searchClosed') === 1) {
            $searchClosed = true;
        }
        $results = [];
        if ($this->checkIfQueryIsIssue($query)) {
            $issue = $this->findIssue($query);
            if (array_key_exists('error', $issue)) {
                $viewData['ErrorMessage'] = $issue['error'];
            } else {
                $results = $this->findDupes($issue['id'], $searchClosed);
                $viewData['mode'] = 'dupes';
                $viewData['issue'] = ['_source' => $issue];
            }
        } else {
            $results = $this->findByQuery($query, $searchClosed);
            $viewData['mode'] = 'query';
        }
        $viewData += $results;
        $viewData['query'] = $query;
        if ($searchClosed) {
            $viewData['searchClosed'] = '1';
        }
        $viewData['filters'] = [];
        if (array_key_exists('filters', $_GET)) {
            $viewData['filters'] = $_GET['filters'];
        }
        $viewData['routeName'] = 'search_search';
        return $this->render('search/search.html.twig', $viewData);
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
     * @param int  $issueId
     * @param bool $searchInClosedIssues
     *
     * @return array
     *
     * @throws Exception
     */
    private function findDupes($issueId, $searchInClosedIssues = false) : array
    {
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

        return $this->doSearch($query);
    }

    /**
     * @param int $issueId
     *
     * @return array|string
     */
    private function findIssue($issueId)
    {
        $forger = ElasticUtility::getIndexByName('issue');
        try {
            $res = $forger->getDocument($issueId);
            $data = $res->getData();
            return $data;
        } catch (Exception $e) {
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
     * @throws Exception
     */
    private function findByQuery($searchTerms, $searchInClosedIssues = false) : array
    {
        $searchTerms = $this->escape($searchTerms);
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

        return $this->doSearch($query);
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

    /**
     * @param array $query
     *
     * @return array
     *
     * @throws Exception
     */
    public function doSearch(array $query) : array
    {
        if (array_key_exists('page', $_GET)) {
            $this->currentPage = (int) $_GET['page'];
        }
        $usedFilters = $this->addFilters();
        if (count($usedFilters) > 0) {
            $query['post_filter'] = $usedFilters;
        }

        $search = ElasticUtility::getIndexByName('issue')->createSearch($query);
        $search->getQuery()->setSize($this->perPage);
        $search->getQuery()->setFrom(($this->currentPage * $this->perPage) - $this->perPage);

        $this->addAggregations($search->getQuery());

        $elasticaResultSet = $search->search();
        $results = $elasticaResultSet->getResults();
        $maxScore = $elasticaResultSet->getMaxScore();
        $aggs = $elasticaResultSet->getAggregations();
        $this->totalHits = $elasticaResultSet->getTotalHits();

        $out = [
            'pagesToLinkTo' => $this->getPages(),
            'currentPage' => $this->currentPage,
            'prev' => $this->currentPage - 1,
            'next' => $this->currentPage < ceil($this->totalHits / $this->perPage) ? $this->currentPage + 1 : 0,
            'totalResults' => $this->totalHits,
            'startingAtItem' => ($this->currentPage * $this->perPage) - ($this->perPage - 1),
            'endingAtItem' => $this->currentPage * $this->perPage,
            'results' => $results,
            'maxScore' => $maxScore,
            'aggs' => $aggs,
        ];
        if ((int) $this->totalHits <= (int) $out['endingAtItem']) {
            $out['endingAtItem'] = (int) $this->totalHits;
        }

        return $out;
    }

    /**
     * Adds filters based on get parameters.
     *
     * @return Query|Query\BoolQuery
     */
    private function addFilters()
    {
        $filters = new Query\BoolQuery();

        if (!array_key_exists('filters', $_GET)) {
            return $filters;
        }
        foreach ($_GET['filters'] as $key => $filterValue) {
            $key = $this->mapFilterNames($key);
            $filterCatCount = 0;
            $filterPart = new Query\BoolQuery();
            foreach ($filterValue as $term => $enabled) {
                if ($enabled === 'true') {
                    $term = str_replace('_', ' ', $term);
                    $filter = new Query\Term();
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
                    ++$filterCatCount;
                }
            }
            if ($filterCatCount > 0) {
                $filters->addMust($filterPart);
            }
        }
        return $filters;
    }

    private function mapFilterNames(string $key): string
    {
        switch ($key) {
            case 'target_version':
                return 'fixed_version.name';
                break;
            default:
                return $key;
        }
    }

    /**
     * @param Query $elasticaQuery
     */
    private function addAggregations($elasticaQuery): void
    {
        $catAggregation = new Terms('Category');
        $catAggregation->setField('category.name');
        $catAggregation->setSize(100);
        $catAggregation->setOrder('_term', 'ASC');
        $elasticaQuery->addAggregation($catAggregation);

        $trackerAggregation = new Terms('Tracker');
        $trackerAggregation->setField('tracker.name');
        $elasticaQuery->addAggregation($trackerAggregation);

        $status = new Terms('Status');
        $status->setField('status.name');
        $elasticaQuery->addAggregation($status);

        $priority = new Terms('Priority');
        $priority->setField('priority.name');
        $elasticaQuery->addAggregation($priority);

        $t3ver = new Terms('TYPO3 Version');
        $t3ver->setField('typo3_version');
        $elasticaQuery->addAggregation($t3ver);

        $targetver = new Terms('Target Version');
        $targetver->setField('fixed_version.name');
        $elasticaQuery->addAggregation($targetver);

        $phpVer = new Terms('PHP Version');
        $phpVer->setField('php_version');
        $elasticaQuery->addAggregation($phpVer);

        /*$age = new \Elastica\Aggregation\Range('Age');
        $age->setParams(array(
            'script' => 'DateTime.now().year - doc[\'updated_on\'].date.year',
            'ranges' => array(
                array('from' => 0,'to' => 1),
                array('from' => 2,'to' => 3),
                array('from' => 4,'to' => 5),
            )
        ));
        $elasticaQuery->addAggregation($age);*/
    }

    /**
     * @return array
     */
    protected function getPages() : array
    {
        $numPages = ceil($this->totalHits / $this->perPage);
        $i = 0;
        /*
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

        $out = [];
        while ($i < $numPages) {
            $out[(int) $i] = ($i + 1);
            ++$i;
        }

        return $out;
    }
}
