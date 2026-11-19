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
use App\Elastic\ElasticUtility;
use App\Enum\IssueStatusEnum;
use App\Enum\TrackerEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    ];
    /**
     * Names as Gerrit delivers them with broken umlauts, mapped to the correct
     * name. Only used to query and merge the data, never shown.
     * Needed until the Server Team fixed the broken umlauts in Gerrit.
     */
    private array $nameAliases = [
        'Annett J�hnichen' => 'Annett Jähnichen',
        'Jasmina Lie�mann' => 'Jasmina Ließmann',
        'Stefan B�rk' => 'Stefan Bürk',
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
                                'status.name' => IssueStatusEnum::closedLabels(),
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
        foreach ($trackerFilters->excluded as $tracker) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = $tracker->label();
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
                                'status.name' => IssueStatusEnum::closedLabels(),
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
            // The columns of the overview: other trackers would add cells without a column
            $viewData[$category['key']] = array_fill_keys(
                array_map(static fn (TrackerEnum $tracker): string => $tracker->label(), [TrackerEnum::BUG, TrackerEnum::FEATURE, TrackerEnum::TASK, TrackerEnum::STORY, TrackerEnum::EPIC]),
                0,
            );
            foreach ($category['tracker']['buckets'] as $tracker) {
                if (array_key_exists($tracker['key'], $viewData[$category['key']])) {
                    $viewData[$category['key']][$tracker['key']] = $tracker['doc_count'];
                }
            }
            ksort($viewData);
        }

        return $this->render('management/bycategory.html.twig', ['data' => $viewData]);
    }

    #[Route(path: '/management/interactions', name: 'management_interactions')]
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
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
                                'fullname' => $this->withNameAliases($this->coreMergers),
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
                    $name = $this->nameAliases[$member['key']] ?? $member['key'];
                    foreach ($member['interaction_type']['buckets'] as $interactionType) {
                        $key = str_replace(':', '_', (string) $interactionType['key']);
                        $out[$dateIdent][$name][$key] = ($out[$dateIdent][$name][$key] ?? 0) + $interactionType['doc_count'];
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
    #[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_ADMIN')]
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

    /**
     * @param string[] $names
     *
     * @return string[]
     */
    private function withNameAliases(array $names): array
    {
        $aliases = array_keys(array_intersect($this->nameAliases, $names));

        return array_values(array_unique([...$names, ...$aliases]));
    }

    private function sanitizeTaggles(array $taggles): array
    {
        $sanitizedArray = [];
        foreach ($taggles as $index => $taggle) {
            if (!is_string($taggle)) {
                throw new \InvalidArgumentException('Taggles need to be strings');
            }
            $result = preg_replace("/[^[:alnum:][:space:][ß]-.']/u", '', html_entity_decode($taggle));
            if ('' !== trim((string) $result)) {
                $sanitizedArray[$index] = $result;
            }
        }

        return $sanitizedArray;
    }
}
