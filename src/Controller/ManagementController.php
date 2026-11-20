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
use App\Elastic\Indices;
use App\Elastic\MonthOverview;
use App\Enum\IssueStatusEnum;
use App\Enum\TrackerEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ManagementController extends AbstractController
{
    public function __construct(
        private readonly Indices $indices,
    ) {
    }

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
        $resultSet = $this->indices->get('issue')->createSearch($fullRequest)->search();
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
        $resultSet = $this->indices->get('issue')->createSearch(CategoryOverview::request())->search();
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
}
