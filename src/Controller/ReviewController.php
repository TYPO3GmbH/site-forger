<?php

namespace App\Controller;

use App\Client\ElasticClient;
use DateTime;
use Elastica\Exception\InvalidException;
use Elastica\Query\BoolQuery;
use Elastica\Query\Range;
use Elastica\Query\Term;
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
        return $this->render('review/status.html.twig', $viewData);
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
        return $this->render('review/hasVerifications.html.twig', $viewData);
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
        return $this->render('review/missesVerifications.html.twig', $viewData);
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
}
