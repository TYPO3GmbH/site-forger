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
use App\Elastic\CategoryOverview;
use App\Elastic\ElasticUtility;
use App\Elastic\MonthOverview;
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
                        'field' => MonthOverview::FIELD,
                        'calendar_interval' => 'year',
                    ],
                    'aggregations' => [
                        'month' => [
                            'date_histogram' => [
                                'field' => MonthOverview::FIELD,
                                'calendar_interval' => 'month',
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
                // The trackers of the counted issues, so a cell finds them in the search with the left out ones left out
                'tracker' => ['terms' => ['field' => 'tracker.name', 'size' => 20]],
            ],
        ];
        foreach ($trackerFilters->excluded as $tracker) {
            $fullRequest['query']['bool']['must_not'][2]['terms']['tracker.name'][] = $tracker->label();
        }
        $resultSet = ElasticUtility::getIndexByName('issue')->createSearch($fullRequest)->search();
        $rows = MonthOverview::rows($resultSet->getAggregations()['year']['buckets'] ?? []);
        $viewData['rows'] = $rows;
        $viewData['maximum'] = MonthOverview::maximum($rows);
        $viewData['total'] = array_sum(array_column($rows, 'total'));
        // Nothing left out: no filter of the trackers in the search, the issues of every tracker count
        $viewData['trackerFilter'] = [] === $trackerFilters->excluded ? [] : array_fill_keys(array_map(
            static fn (array $bucket): string => str_replace(' ', '_', (string) $bucket['key']),
            $resultSet->getAggregations()['tracker']['buckets'] ?? [],
        ), 'true');
        // Tracker => the parameters of the page with it left out or taken in again, for the chips
        $viewData['trackers'] = array_map(
            static fn (TrackerEnum $tracker): array => [
                'tracker' => $tracker,
                'included' => !$trackerFilters->isExcluded($tracker),
                'toggle' => ['trackerFilters' => array_fill_keys(array_map(
                    static fn (TrackerEnum $excluded): string => $excluded->severity(),
                    $trackerFilters->isExcluded($tracker)
                        ? array_values(array_filter($trackerFilters->excluded, static fn (TrackerEnum $excluded): bool => $excluded !== $tracker))
                        : [...$trackerFilters->excluded, $tracker],
                ), 'true')],
            ],
            CategoryOverview::TRACKERS,
        );

        return $this->render('management/index.html.twig', $viewData);
    }

    #[Route(path: '/management/bycategory', name: 'management_bycategory')]
    public function byCategory(): Response
    {
        $resultSet = ElasticUtility::getIndexByName('issue')->createSearch(CategoryOverview::request())->search();
        $aggregations = $resultSet->getAggregations();
        $rows = CategoryOverview::rows($aggregations['category']['buckets'] ?? []);
        $withoutCategory = CategoryOverview::withoutCategory($aggregations['without_category'] ?? null);

        return $this->render('management/bycategory.html.twig', [
            'rows' => $rows,
            'withoutCategory' => $withoutCategory,
            // Of every open issue, those without category, too
            'sums' => CategoryOverview::sums([...$rows, ...(null === $withoutCategory ? [] : [$withoutCategory])]),
            'trackers' => CategoryOverview::TRACKERS,
        ]);
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
