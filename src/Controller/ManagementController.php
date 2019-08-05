<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Client\ElasticClient;
use App\Entity\TrackerFilters;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ManagementController extends AbstractController
{
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
    }

    /**
     * @Route("/management", name="management_index")
     */
    public function index(): Response
    {
        $viewData['routeName'] = 'management_index';
        $trackerFilters = new TrackerFilters();
        $viewData['trackerFilters'] = $trackerFilters;
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
        $search = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
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
        $viewData['aggs'] = $out;
        return $this->render('management/index.html.twig', $viewData);
    }
}
