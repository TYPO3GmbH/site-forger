<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\DTO\TrackerFilters;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use T3G\ElasticUtility\ElasticUtility;

class ManagementController extends AbstractController
{
    private array $coreMergers = [
        'Andreas Kienast',
        'Andreas Nedbal',
        'Anja Leichsenring',
        'Annett Jähnichen',
        'Benjamin Franzke',
        'Benjamin Kott',
        'Benni Mack',
        'Christian Kuhn',
        'Georg Ringer',
        'Helmut Hummel',
        'Jasmina Ließmann',
        'Kevin Appelt',
        'Lina Wolf',
        'Nikita Hovratov',
        'Oliver Bartsch',
        'Oliver Hader',
        'Riccardo De Contardi',
        'Simon Praetorius',
        'Stefan Bürk',
        // Added until Server Team managed to fix the broken umlauts in Gerrit
        'Annett J�hnichen',
        'Jasmina Lie�mann',
        'Stefan B�rk',
    ];
    private array $docTeam = [
        'Alexander Nitsche',
        'Daniel Siepmann',
        'David Bruchmann',
        'Lina Wolf',
        'Martin Bless',
        'Mathias Brodala',
        'Susanne Moog',
        'Sybille Peters',
        'Tom Warwick',
        'flicstar',
    ];

    #[Route(path: '/management', name: 'management_index')]
    public function index(Request $request): Response
    {
        $viewData = [];
        $viewData['routeName'] = 'management_index';
        $trackerFilters = new TrackerFilters($request);
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
        if ($trackerFilters->feature) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = 'Feature';
        }
        if ($trackerFilters->task) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = 'Task';
        }
        if ($trackerFilters->bug) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = 'Bug';
        }
        if ($trackerFilters->epic) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = 'Epic';
        }
        if ($trackerFilters->story) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = 'Story';
        }
        $search = ElasticUtility::getIndexByName('issue')->createSearch($fullRequest);
        $resultSet = $search->search();
        foreach ($resultSet->getAggregations() as $aggregation) {
            foreach ($aggregation['buckets'] as $bucket) {
                $dateParts = explode('/', (string) $bucket['key_as_string']);
                $yearName = $dateParts[0];
                // prefill array with 12 index, for 12 month
                $months = array_fill(0, 12, []);
                foreach ($bucket['month']['buckets'] as $month) {
                    $dateParts = explode('/', (string) $month['key_as_string']);
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

    #[Route(path: '/management/bycategory', name: 'management_bycategory')]
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
                        'size' => 1000,
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
        $search = ElasticUtility::getIndexByName('issue')->createSearch($fullRequest);
        $resultSet = $search->search();
        $aggregations = $resultSet->getAggregations();
        $viewData = [];
        foreach ($aggregations['category']['buckets'] as $category) {
            $viewData[$category['key']] = [
                'Bug' => 0,
                'Feature' => 0,
                'Task' => 0,
                'Story' => 0,
                'Epic' => 0,
            ];
            foreach ($category['tracker']['buckets'] as $tracker) {
                $viewData[$category['key']][$tracker['key']] = $tracker['doc_count'];
            }
            ksort($viewData);
        }

        return $this->render('management/bycategory.html.twig', ['data' => $viewData]);
    }

    #[Route(path: '/management/interactions', name: 'management_interactions')]
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
                                    'gte' => 'now-5M',
                                ],
                            ],
                        ],
                        [
                            'terms' => [
                                'fullname' => $this->coreMergers,
                            ],
                        ],
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
                    ],
                ],
            ],
        ];
        $search = ElasticUtility::getIndexByName('interactions')->createSearch($fullRequest);
        $resultSet = $search->search();
        $aggregations = $resultSet->getAggregations();
        foreach ($aggregations as $aggregation) {
            foreach ($aggregation['buckets'] as $bucket) {
                $monthParts = explode('/', (string) $bucket['key_as_string']);
                $dateIdent = $monthParts[0] . '/' . $monthParts[1];
                foreach ($this->coreMergers as $coreMember) {
                    $out[$dateIdent][$coreMember] = [];
                }
                foreach ($bucket['member']['buckets'] as $member) {
                    foreach ($member['interaction_type']['buckets'] as $interactionType) {
                        $key = str_replace(':', '_', (string) $interactionType['key']);
                        $out[$dateIdent][$member['key']][$key] = $interactionType['doc_count'];
                    }
                }
            }
        }
        krsort($out);

        return $this->render('management/interactions.html.twig', [
            'aggs' => $out,
            'coreTeamMergers' => $this->coreMergers,
        ]);
    }

    #[Route(path: '/management/docs-interactions', name: 'management_docs_interactions')]
    public function docsInteractions(): Response
    {
        if (isset($_GET['taggles'])) {
            $this->docTeam = $this->sanitizeTaggles($_GET['taggles']);
            sort($this->docTeam);
        }
        $out = [];
        $fullRequest = [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'range' => [
                                'interacted_on' => [
                                    'gte' => 'now-5M',
                                ],
                            ],
                        ],
                        [
                            'terms' => [
                                'fullname' => $this->docTeam,
                            ],
                        ],
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
                    ],
                ],
            ],
        ];
        $search = ElasticUtility::getIndexByName('interactions')->createSearch($fullRequest);
        $resultSet = $search->search();
        $aggregations = $resultSet->getAggregations();
        foreach ($aggregations as $aggregation) {
            foreach ($aggregation['buckets'] as $bucket) {
                $monthParts = explode('/', (string) $bucket['key_as_string']);
                $dateIdent = $monthParts[0] . '/' . $monthParts[1];
                foreach ($this->docTeam as $docMember) {
                    $out[$dateIdent][$docMember] = [];
                }
                foreach ($bucket['member']['buckets'] as $member) {
                    foreach ($member['interaction_type']['buckets'] as $interactionType) {
                        $key = str_replace(':', '_', (string) $interactionType['key']);
                        $out[$dateIdent][$member['key']][$key] = $interactionType['doc_count'];
                    }
                }
            }
        }
        krsort($out);

        return $this->render('management/docs-interactions.html.twig', [
            'aggs' => $out,
            'docTeam' => $this->docTeam,
        ]);
    }

    #[Route(path: '/management/leaderboard/{type}/{dateRange}', name: 'management_leaderboard', requirements: ['type' => '(individual|company)', 'dateRange' => '(week|month|quarter|year)'], defaults: ['type' => 'company', 'dateRange' => 'quarter'])]
    public function leaderBoard(string $type, string $dateRange): Response
    {
        // Create Navs
        $typeNav = [
            'individual' => [
                'title' => 'Individual contribution',
                'active' => false,
                'type' => 'individual',
            ],
            'company' => [
                'title' => 'Company contribution',
                'active' => false,
                'type' => 'company',
            ],
        ];
        $dateNav = [
            'week' => [
                'title' => 'per week',
                'active' => false,
                'type' => 'week',
            ],
            'month' => [
                'title' => 'per month',
                'active' => false,
                'type' => 'month',
            ],
            'quarter' => [
                'title' => 'per quarter',
                'active' => false,
                'type' => 'quarter',
            ],
            'year' => [
                'title' => 'per year',
                'active' => false,
                'type' => 'year',
            ],
        ];
        // Set active states
        $dateNav[$dateRange]['active'] = true;
        $typeNav[$type]['active'] = true;

        // Set fields to aggregate on
        $aggregationTypeField = 'fullname';
        $title = 'Individual contribution';
        if ('company' === $type) {
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
                                ],
                            ],
                        ],
                    ],
                    'must_not' => [
                        [
                            'terms' => [
                                'fullname' => [
                                    'TYPO3com',
                                    'Mr. Jenkins',
                                    'core-ci',
                                    'Replication from review.typo3.org and git.typo3.org',
                                    'github-actions[bot]',
                                    'dependabot[bot]',
                                ],
                            ],
                        ],
                    ],
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
                                    'points' => 'desc',
                                ],
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
        $search = ElasticUtility::getIndexByName('karmapoints')->createSearch($fullRequest);
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
            'dateRange' => $dateRange,
        ]);
    }

    private function getDateIdentifierByDateRangeType(string $dateRangeType, string $bucketKey): string
    {
        $dateRangeIdentifier = 'unclear';
        switch ($dateRangeType) {
            case 'week':
                try {
                    $time = new \DateTime($bucketKey);
                } catch (Exception) {
                    $time = new \DateTime('now');
                }
                $dateRangeIdentifier = $time->format('Y / \C\W W');
                break;
            case 'month':
                $monthParts = explode('/', $bucketKey);
                $dateRangeIdentifier = $monthParts[0] . '/' . $monthParts[1];
                break;
            case 'quarter':
                $monthParts = explode('/', $bucketKey);
                $quarterIdentifier = match ($monthParts[1]) {
                    '01' => 'Q1',
                    '04' => 'Q2',
                    '07' => 'Q3',
                    '10' => 'Q4',
                    default => 'dafuq?',
                };
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
                throw new \InvalidArgumentException('Taggles need to be strings');
            }
            $result = preg_replace("/[^[:alnum:][:space:][ß]-.']/u", '', html_entity_decode($taggle));
            if ('' !== trim($result)) {
                $sanitizedArray[$index] = $result;
            }
        }

        return $sanitizedArray;
    }
}
