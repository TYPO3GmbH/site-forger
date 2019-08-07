<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Client\ElasticClient;
use DateTime;
use Elastica\Exception\InvalidException;
use Elastica\Query\BoolQuery;
use Elastica\Query\Range;
use Elastica\Query\Term;
use Elastica\ResultSet;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ReviewController extends AbstractController
{
    /**
     * @var int
     */
    private $perPage = 25;

    /**
     * @var int
     */
    private $currentPage = 1;

    /**
     * @var
     */
    private $totalHits;

    /**
     * @var string
     */
    private $dateInterval = 'month';

    /**
     * @var ElasticClient
     */
    private $elasticClient;

    /**
     * @var ParameterBagInterface
     */
    private $parameters;

    public function __construct(ElasticClient $elasticClient, ParameterBagInterface $parameters)
    {
        $this->elasticClient = $elasticClient;
        $this->parameters = $parameters;
        if (array_key_exists('page', $_GET)) {
            $this->currentPage = (int) $_GET['page'];
        }
    }

    /**
     * @Route("/gerrit/status", name="gerrit_index")
     */
    public function status(): Response
    {
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

                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED',
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip',
                            ],
                        ],
                    ],
                ],
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $this->queryAggregations(),
        ];
        $viewData = $this->search($fullRequest);
        $viewData['routeName'] = 'gerrit_index';
        $viewData['headline'] = 'Status';
        return $this->render('review/genericReviewView.html.twig', $viewData);
    }

    /**
     * @Route("/gerrit/merge-ready", name="gerrit_hasverifications")
     */
    public function hasVerifications(): Response
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'positive_reviews' => [
                                    'gte' => 1,
                                ],
                            ],
                        ],
                        [
                            'range' => [
                                'positive_verifications' => [
                                    'gte' => 1,
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED',
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip',
                            ],
                        ],
                    ],
                ],
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $this->queryAggregations(),
        ];
        $viewData = $this->search($fullRequest);
        $viewData['routeName'] = 'gerrit_hasverifications';
        $viewData['headline'] = 'Merge Ready';
        return $this->render('review/genericReviewView.html.twig', $viewData);
    }

    /**
     * @Route("/gerrit/misses-verification", name="gerrit_missesverifications")
     */
    public function missesVerifications(): Response
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'positive_reviews' => [
                                    'gte' => 1,
                                ],
                            ],
                        ],
                        [
                            'range' => [
                                'positive_verifications' => [
                                    'lt' => 1,
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED',
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip',
                            ],
                        ],
                    ],
                ],
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $this->queryAggregations(),
        ];
        $viewData = $this->search($fullRequest);
        $viewData['routeName'] = 'gerrit_missesverifications';
        $viewData['headline'] = 'Needs Verification';
        return $this->render('review/genericReviewView.html.twig', $viewData);
    }

    /**
     * @Route("/gerrit/misses-review", name="gerrit_missesreview")
     */
    public function missesReview(): Response
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'positive_reviews' => [
                                    'lt' => 1,
                                ],
                            ],
                        ],
                        [
                            'range' => [
                                'positive_verifications' => [
                                    'gte' => 1,
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED',
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip',
                            ],
                        ],
                    ],
                ],
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $this->queryAggregations(),
        ];
        $viewData = $this->search($fullRequest);
        $viewData['routeName'] = 'gerrit_missesreview';
        $viewData['headline'] = 'Needs Review';
        return $this->render('review/genericReviewView.html.twig', $viewData);
    }

    /**
     * @Route("/gerrit/rebase", name="gerrit_rebase")
     */
    public function rebase(): Response
    {
        $aggs = $this->queryAggregations();
        unset($aggs['Mergeable'], $aggs['Patchsets'], $aggs['Releases'], $aggs['Topic']);
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'term' => [
                                'mergeable' => 'no',
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED',
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip',
                            ],
                        ],
                    ],
                ],
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $aggs,
        ];
        $viewData = $this->search($fullRequest);
        $viewData['routeName'] = 'gerrit_rebase';
        $viewData['headline'] = 'Needs Rebase';
        return $this->render('review/genericReviewView.html.twig', $viewData);
    }

    /**
     * @Route("/gerrit/files", name="gerrit_files")
     */
    public function getFiles() : Response
    {
        $viewData['routeName'] = 'gerrit_files';
        $viewData['searchRouteName'] = 'gerrit_files';
        $viewData['searchPlaceholder'] = 'full relative path in GIT repo';
        $viewData['results'] = [];
        if (array_key_exists('query', $_GET)) {
            $searchClosed = false;
            if (array_key_exists('searchClosed', $_GET) && $_GET['searchClosed'] === '1') {
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

                            ],
                        ],
                        'must_not' => [
                            [
                                'terms' => [
                                    'reviewstatus' => [
                                        'MERGED',
                                        'ABANDONED',
                                    ],
                                ],

                            ],
                        ],
                    ],
                ],
                'sort' => $this->querySort(),
                'size' => $this->perPage,
                'from' => $this->currentPage * $this->perPage - $this->perPage,
                'aggregations' => $this->queryAggregations(),
            ];
            if ($searchClosed) {
                unset($fullRequest['query']['bool']['must_not']);
            }
            $viewData = $this->search($fullRequest);
            $viewData['routeName'] = 'gerrit_files';
            if ($searchClosed) {
                $viewData['searchClosed'] = '1';
            }
            $viewData['query'] = htmlspecialchars($_GET['query']);
        }
        return $this->render('review/files.html.twig', $viewData);
    }

    /**
     * @Route("/gerrit/negative-vote-cleanup", name="gerrit_negative_vote_cleanup")
     */
    public function negativeVoteCleanup(): Response
    {
        $aggregations = $this->queryAggregations();
        unset(
            $aggregations['Mergeable'],
            $aggregations['Patchsets'],
            $aggregations['Affected Files'],
            $aggregations['Topic'],
            $aggregations['Owner']
        );
        $fullRequest = [
            'query' => [
                'bool' => [
                    'should' => [
                        [
                            'range' => [
                                'negative_reviews' => [
                                    'gte' => 1,
                                ],
                            ],
                        ],
                        [
                            'range' => [
                                'negative_verifications' => [
                                    'gte' => 1,
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED',
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip',
                            ],
                        ],
                    ],
                ],
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $aggregations,
        ];
        $viewData = $this->search($fullRequest);
        $viewData['routeName'] = 'gerrit_negative_vote_cleanup';
        $viewData['headline'] = 'Negative Vote Cleanup';
        return $this->render('review/genericReviewView.html.twig', $viewData);
    }

    /**
     * @Route("/gerrit/wip", name="gerrit_wip")
     */
    public function wip(): Response
    {
        $aggregations = $this->queryAggregations();
        unset(
            $aggregations['Patchsets'],
            $aggregations['Affected Files']
        );
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'term' => [
                                'reviewtags' => 'WIP',
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED',
                                ],
                            ],

                        ],
                    ],
                ],
            ],
            'sort' => [
                'updated_on' => 'asc',
                'insertions' => 'asc',
                'deletions' => 'asc',
                'patchsets' => 'desc',
            ],
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $aggregations
        ];
        $viewData = $this->search($fullRequest);
        $viewData['routeName'] = 'gerrit_wip';
        $viewData['headline'] = 'Work in Progress';
        return $this->render('review/genericReviewView.html.twig', $viewData);
    }

    /**
     * @Route("/gerrit/core-team-merge", name="gerrit_coreteam_merge")
     */
    public function coreTeamActionRequired(): Response
    {
        $aggregations = $this->queryAggregations();
        unset(
            $aggregations['Mergeable'],
            $aggregations['Patchsets'],
            $aggregations['Affected Files']
        );
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'positive_reviews' => [
                                    'gte' => 1,
                                ],
                            ],
                        ],
                        [
                            'range' => [
                                'positive_verifications' => [
                                    'gte' => 1,
                                ],
                            ],
                        ],
                        [
                            'term' => [
                                'mergeable' => 'yes',
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED',
                                ],
                            ],

                        ],
                        [
                            'term' => [
                                'subject' => 'wip',
                            ],
                        ],
                        [
                            'range' => [
                                'negative_reviews' => [
                                    'gte' => 1,
                                ],
                            ],
                        ],
                        [
                            'range' => [
                                'negative_verifications' => [
                                    'gte' => 1,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'sort' => $this->querySort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $aggregations,
        ];
        $viewData = $this->search($fullRequest);
        $viewData['routeName'] = 'gerrit_coreteam_merge';
        $viewData['headline'] = 'Core Team Action Required';
        return $this->render('review/genericReviewView.html.twig', $viewData);
    }

    /**
     * @Route("/gerrit/stale-issues", name="gerrit_stale_issues")
     */
    public function staleIssues(): Response
    {
        $re = '/http[s]*:\/\/review\.typo3\.org\/([\d]*)/';
        $staleIssues = [];

        $resultSet = $this->getUnderReviewIssues();
        foreach ($resultSet->getResults() as $result) {
            $reviewIds = [];
            foreach ($result->getData()['journals'] as $journal) {
                if (isset($journal['notes'])) {
                    preg_match_all($re, $journal['notes'], $matches);
                    $id = $matches[1][0] ?? null;
                    if ($id !== null && $id !== '') {
                        $reviewIds[] = $id;
                    }
                }
            }
            $reviewIds = array_unique($reviewIds);
            $resultSet = $this->getOpenReviewsByIds($reviewIds);
            if (!$resultSet->count()) {
                $staleIssues[] = $result->getData();
            }
        }
        $viewData['issues'] = $staleIssues;
        $viewData['routeName'] = 'gerrit_stale_issues';
        return $this->render('review/staleIssues.html.twig', $viewData);
    }

    /**
     * @param array $fullRequest
     *
     * @return array
     * @throws InvalidException
     */
    protected function search(array $fullRequest): array
    {
        $viewData = [];
        $usedFilters = $this->addFilters();
        if (count($usedFilters->toArray()) > 0) {
            $fullRequest['post_filter'] = $usedFilters;
        }
        $query = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
        $query->addType('review');
        $resultSet = $query->search();
        $this->totalHits = $resultSet->getTotalHits();
        if ((int) $this->totalHits <= ($this->currentPage * $this->perPage)) {
            $viewData['endingAtItem'] = (int) $this->totalHits;
        } else {
            $viewData['endingAtItem'] = $this->currentPage * $this->perPage;
        }
        $viewData['results'] = $resultSet->getResults();
        $viewData['pagesToLinkTo'] = $this->getPages();
        $viewData['currentPage'] = $this->currentPage;
        $viewData['prev'] = $this->currentPage - 1;
        $viewData['next'] = $this->currentPage < ceil($this->totalHits / $this->perPage) ? $this->currentPage + 1 : 0;
        $viewData['totalResults'] = $this->totalHits;
        $viewData['startingAtItem'] = ($this->currentPage * $this->perPage) - ($this->perPage - 1);
        $viewData['aggregations'] = $resultSet->getAggregations();
        if (array_key_exists('filters', $_GET)) {
            $viewData['filters'] = $_GET['filters'];
        } else {
            $viewData['filters'] = [];
        }
        if (array_key_exists('query', $_GET)) {
            $viewData['query'] = $_GET['query'];
        } else {
            $viewData['query'] = '';
        }
        return $viewData;
    }

    /**
     * Makes the date string from elastic JS compatible.
     *
     * @param string $in
     *
     * @return string
     */
    protected function fixTime($in) : string
    {
        return str_replace('/', '-', substr($in, 0, 10));
    }

    protected function addFilters(): BoolQuery
    {
        $filters = new BoolQuery();

        if (!array_key_exists('filters', $_GET)) {
            return $filters;
        }
        foreach ($_GET['filters'] as $key => $filterValue) {
            $filterPart = new BoolQuery();
            $filterCatCount = 0;
            foreach ($filterValue as $term => $enabled) {
                if ($enabled === 'true') {
                    $term = str_replace('_', ' ', $term);
                    if ($key === 'updated_on') {
                        $filter = new Range();
                        $startDate = DateTime::createFromFormat('U', (int) $term / 1000);
                        $endDate = DateTime::createFromFormat('U', (int) $term / 1000)->modify('+1 month -1 second');
                        $filter->addField(
                            $key,
                            [
                                'gte' => $startDate->format('Y/m/d H:i:s'),
                                'lte' => $endDate->format('Y/m/d H:i:s'),
                            ]
                        );
                    } else {
                        $filter = new Term();
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

    /**
     * @return array
     */
    protected function queryAggregations() : array
    {
        return [
            'Releases' => [
                'terms' => [
                    'field' => 'releases',
                ],
            ],
            'Affected Files' => [
                'terms' => [
                    'field' => 'affected_files',
                ],
            ],
            'Topic' => [
                'terms' => [
                    'field' => 'topic',
                    'order' => [
                        '_term' => 'asc',
                    ],
                ],
            ],
            'Patchsets' => [
                'terms' => [
                    'field' => 'patchsets',
                ],
            ],
            'Mergeable' => [
                'terms' => [
                    'field' => 'mergeable',
                ],
            ],
            'Owner' => [
                'terms' => [
                    'field' => 'owner',
                    'size' => 10000,
                ],
            ],
            'Updated on' => [
                'date_histogram' => [
                    'field' => 'updated_on',
                    'interval' => $this->dateInterval,
                    'format' => 'MMMM yyyy',
                ],
            ],
        ];
    }

    /**
     * Query sorting.
     *
     * @return array
     */
    protected function querySort() : array
    {
        return [
            'updated_on' => 'desc',
            'insertions' => 'asc',
            'deletions' => 'asc',
            'patchsets' => 'desc',
        ];
    }

    /**
     * Returns an array of linkable pages.
     *
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

    protected function getOpenReviewsByIds(array $ids) : ResultSet
    {
        $filter = [];
        foreach ($ids as $reviewId) {
            $filter[] = [
                'term' => [
                    'id' => $reviewId,
                ],
            ];
        }
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must_not' => [
                        [
                            'terms' => [
                                'reviewstatus' => [
                                    'MERGED',
                                    'ABANDONED',
                                ],
                            ],
                        ],
                    ],
                    'should' => $filter
                ],
            ],
            'sort' => [
                'updated_on' => 'asc',
                'insertions' => 'asc',
                'deletions' => 'asc',
                'patchsets' => 'desc',
            ],
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
        ];
        $query = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
        $query->addType('review');

        return $query->search();
    }

    /**
     * @return ResultSet
     *
     * @throws InvalidException
     */
    protected function getUnderReviewIssues() : ResultSet
    {
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'term' => [
                                'status.id' => '8',
                            ],
                        ],
                    ],
                ],
            ],
            'sort' => [
                'updated_on' => 'asc',
                'insertions' => 'asc',
                'deletions' => 'asc',
                'patchsets' => 'desc',
            ],
            'size' => 1000,
            'from' => 0,
            'aggregations' => [
                'Releases' => [
                    'terms' => [
                        'field' => 'releases',
                    ],
                ],
                'Updated on' => [
                    'date_histogram' => [
                        'field' => 'updated_on',
                        'interval' => $this->dateInterval,
                        'format' => 'MMMM yyyy',
                    ],
                ],
            ],
        ];
        $query = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
        $query->addType('issue');

        return $query->search();
    }
}
