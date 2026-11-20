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
use App\Enum\TrackerEnum;
use App\Report\CategoryOverview;
use App\Report\MonthOverview;
use App\Report\YearRow;
use App\Repository\IssueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ManagementController extends AbstractController
{
    public function __construct(
        private readonly IssueRepository $issues,
    ) {
    }

    #[Route(path: '/management', name: 'management_index')]
    public function index(Request $request): Response
    {
        $viewData = [];
        $viewData['routeName'] = 'management_index';
        $trackerFilters = new TrackerFilters($request);
        $viewData['trackerFilters'] = $trackerFilters;
        $request = MonthOverview::request($trackerFilters->excludedLabels());
        $issues = $this->issues->search($request);
        $rows = MonthOverview::rows($issues->buckets('year'));
        $viewData['rows'] = $rows;
        $viewData['maximum'] = MonthOverview::maximum($rows);
        $viewData['total'] = array_sum(array_map(static fn (YearRow $row): int => $row->total, $rows));
        // Nothing left out: no filter of the trackers in the search, the issues of every tracker count
        $trackerNames = array_map(
            static fn (string $name): string => str_replace(' ', '_', $name),
            array_keys($issues->counts('tracker')),
        );
        $viewData['trackerFilter'] = [] === $trackerFilters->excluded ? [] : array_fill_keys($trackerNames, 'true');
        // The chips of the trackers
        $viewData['trackers'] = array_map(
            static fn (TrackerEnum $tracker): array => [
                'tracker' => $tracker,
                'included' => !$trackerFilters->isExcluded($tracker),
                'toggle' => $trackerFilters->toggled($tracker),
            ],
            CategoryOverview::TRACKERS,
        );

        return $this->render('management/index.html.twig', $viewData);
    }

    #[Route(path: '/management/bycategory', name: 'management_bycategory')]
    public function byCategory(): Response
    {
        $issues = $this->issues->search(CategoryOverview::request());
        $rows = CategoryOverview::rows($issues->buckets('category'));
        $withoutCategory = CategoryOverview::withoutCategory($issues->aggregation('without_category'));

        return $this->render('management/bycategory.html.twig', [
            'rows' => $rows,
            'withoutCategory' => $withoutCategory,
            // Of every open issue, those without category, too
            'sums' => CategoryOverview::sums(null === $withoutCategory ? $rows : [...$rows, $withoutCategory]),
            'trackers' => CategoryOverview::TRACKERS,
        ]);
    }
}
