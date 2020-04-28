<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Client\ElasticClient;
use App\Entity\Company;
use App\Entity\TrackerFilters;
use DateTime;
use Exception;
use InvalidArgumentException;
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

    private $coreMergers = [
        'Alexander Schnitzler',
        'Andreas Fernandez',
        'Anja Leichsenring',
        'Benjamin Franzke',
        'Benni Mack',
        'Christian Eßl',
        'Claus Due',
        'Daniel Goerz',
        'Daniel Siepmann',
        'Frank Nägler',
        'Georg Ringer',
        'Helmut Hummel',
        'Manuel Selbach',
        'Oliver Hader',
        'Riccardo De Contardi',
        'Richard Haeser',
        'Susanne Moog',
        'Tobi Kretschmann',
        'Tymoteusz Motylewski',
    ];

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

    /**
     * @Route("/management/bycategory", name="management_bycategory")
     */
    public function byCategory(): Response
    {
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
                'category' => [
                    'terms' => [
                        'field' => 'category.name',
                        'size' => 1000
                    ],
                    'aggregations' => [
                        'tracker' => [
                            'terms' => [
                                'field' => 'tracker.name',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $search = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
        $search->addType('issue');
        $resultSet = $search->search();
        $aggregations = $resultSet->getAggregations();
        $viewData = [];
        foreach ($aggregations['category']['buckets'] as $category) {
            $viewData[$category['key']] = [
                'Bug' => 0,
                'Feature' => 0,
                'Task' => 0,
                'Story' => 0,
                'Epic' => 0
            ];
            foreach ($category['tracker']['buckets'] as $tracker) {
                $viewData[$category['key']][$tracker['key']] = $tracker['doc_count'];
            }
            ksort($viewData);
        }
        return $this->render('management/bycategory.html.twig', ['data' => $viewData]);
    }

    /**
     * @Route("/management/interactions", name="management_interactions")
     */
    public function interactions(): Response
    {
        if (isset($_GET['taggles'])) {
            $this->coreMergers = $this->sanitizeTaggles($_GET['taggles']);
            sort($this->coreMergers);
        }
        $out = [];
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'interacted_on' => [
                                    'gte' => 'now-5M'
                                ]
                            ]
                        ],
                        [
                            'terms' => [
                                'fullname' => $this->coreMergers,
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
        $search = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
        $search->addType('interactions');
        $resultSet = $search->search();
        $aggregations = $resultSet->getAggregations();
        foreach ($aggregations as $aggregation) {
            foreach ($aggregation['buckets'] as $bucket) {
                $monthParts = explode('/', $bucket['key_as_string']);
                $dateIdent = $monthParts[0] . '/' . $monthParts[1];
                foreach ($this->coreMergers as $coreMember) {
                    $out[$dateIdent][$coreMember] = [];
                }
                foreach ($bucket['member']['buckets'] as $member) {
                    foreach ($member['interaction_type']['buckets'] as $interactionType) {
                        $key = str_replace(':', '_', $interactionType['key']);
                        $out[$dateIdent][$member['key']][$key] = $interactionType['doc_count'];
                    }
                }
            }
        }
        krsort($out);
        return $this->render('management/interactions.html.twig', [
            'aggs' => $out,
            'coreTeamMergers' => $this->coreMergers
        ]);
    }

    /**
     * @Route(
     *     "/management/leaderboard/{type}/{dateRange}",
     *     defaults={
     *       "type"="company",
     *       "dateRange"="quarter"
     *     },
     *     requirements={
     *       "type"="(individual|company)",
     *       "dateRange"="(week|month|quarter|year)"
     *     },
     *     name="management_leaderboard"
     * )
     * @param string $type The type of board to show. Possible values are "individual" and "company"
     * @param string $dateRange
     * @return Response
     */
    public function leaderBoard(string $type, string $dateRange): Response
    {
        // Create Navs
        $typeNav = [
            'individual' => [
                'title' => 'Individual contribution',
                'active' => false,
                'type' => 'individual'
            ],
            'company' => [
                'title' => 'Company contribution',
                'active' => false,
                'type' => 'company'
            ]
        ];
        $dateNav = [
            'week' => [
                'title' => 'per week',
                'active' => false,
                'type' => 'week'
            ],
            'month' => [
                'title' => 'per month',
                'active' => false,
                'type' => 'month'
            ],
            'quarter' => [
                'title' => 'per quarter',
                'active' => false,
                'type' => 'quarter'
            ],
            'year' => [
                'title' => 'per year',
                'active' => false,
                'type' => 'year'
            ],
        ];
        // Set active states
        $dateNav[$dateRange]['active'] = true;
        $typeNav[$type]['active'] = true;

        // Set fields to aggregate on
        $aggregationTypeField = 'fullname';
        $title = 'Individual contribution';
        if ($type === 'company') {
            $aggregationTypeField = 'company';
            $title = 'Company contribution';
        }
        // Define dateRanges based on selected date aggregation
        switch ($dateRange) {
            case 'week':
                $dateStart = 'now-20w';
                $title .= ' per week';
                break;
            case 'month':
                $dateStart = 'now-11M';
                $title .= ' per month';
                break;
            case 'quarter':
                $dateStart = 'now-35M';
                $title .= ' per quarter';
                break;
            case 'year':
                $dateStart = '2010/01/01 00:00:00';
                $title .= ' per year';
                break;
            default:
                $dateStart = '2012/01/01 00:00:00';
        }
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'interacted_on' => [
                                    'gte' => $dateStart,
                                ]
                            ]
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'fullname' => [
                                    'TYPO3com',
                                    'Mr. Jenkins'
                                ]
                            ]
                        ]
                    ]
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'month' => [
                    'date_histogram' => [
                        'field' => 'interacted_on',
                        'interval' => $dateRange,
                    ],
                    'aggregations' => [
                        'member' => [
                            'terms' => [
                                'field' => $aggregationTypeField,
                                'size' => 20,
                                'order' => [
                                    'points' => 'desc'
                                ]
                            ],
                            'aggregations' => [
                                'points' => [
                                    'sum' => [
                                        'field' => 'points',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $out = [];
        $search = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->createSearch($fullRequest);
        $search->addType('karmapoints');
        $resultSet = $search->search();
        $aggregations = $resultSet->getAggregations();
        foreach ($aggregations as $month) {
            foreach ($month['buckets'] as $bucket) {
                $contributors = [];
                $dateIdent = $this->getDateIdentifierByDateRangeType($dateRange, $bucket['key_as_string']);
                foreach ($bucket['member']['buckets'] as $contributor) {
                    $contributors[$contributor['key']] = $contributor['points']['value'];
                }
                $out[$dateIdent] = $contributors;
            }
        }
        krsort($out);
        return $this->render('management/leaderboard.html.twig', [
            'aggs' => $out,
            'headline' => $title,
            'typeMenu' => $typeNav,
            'dateMenu' => $dateNav,
            'type' => $type,
            'dateRange' => $dateRange
        ]);
    }

    private function getDateIdentifierByDateRangeType(string $dateRangeType, string $bucketKey): string
    {
        $dateRangeIdentifier = 'unclear';
        switch ($dateRangeType) {
            case 'week':
                try {
                    $time = new DateTime($bucketKey);
                } catch (Exception $e) {
                    $time = new DateTime('now');
                }
                $dateRangeIdentifier = $time->format('Y / \C\W W');
                break;
            case 'month':
                $monthParts = explode('/', $bucketKey);
                $dateRangeIdentifier = $monthParts[0] . '/' . $monthParts[1];
                break;
            case 'quarter':
                $monthParts = explode('/', $bucketKey);
                switch ($monthParts[1]) {
                    case '01':
                        $quarterIdentifier = 'Q1';
                        break;
                    case '04':
                        $quarterIdentifier = 'Q2';
                        break;
                    case '07':
                        $quarterIdentifier = 'Q3';
                        break;
                    case '10':
                        $quarterIdentifier = 'Q4';
                        break;
                    default:
                        $quarterIdentifier = 'dafuq?';
                }
                $dateRangeIdentifier = $monthParts[0] . '/' . $quarterIdentifier;
                break;
            case 'year':
                $monthParts = explode('/', $bucketKey);
                $dateRangeIdentifier = $monthParts[0];
                break;
        }
        return $dateRangeIdentifier;
    }

    private function sanitizeTaggles(array $taggles): array
    {
        $sanitizedArray = [];
        foreach ($taggles as $index => $taggle) {
            if (!is_string($taggle)) {
                throw new InvalidArgumentException('Taggles need to be strings');
            }
            $result = preg_replace("/[^[:alnum:][:space:][ß]-.']/u", '', html_entity_decode($taggle));
            if (trim($result) !== '') {
                $sanitizedArray[$index] = $result;
            }
        }
        return $sanitizedArray;
    }
}
