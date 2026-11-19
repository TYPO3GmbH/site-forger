<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Charts\ChartStyle;
use App\Charts\Types\Mergeable;
use App\Elastic\ElasticUtility;
use App\Elastic\IssueSearch;
use App\Elastic\ReviewLists;
use App\Enum\IssueSortEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStatusEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/', name: 'dashboard_')]
class DashboardController extends AbstractController
{
    /**
     * How many entries the lists of the dashboard show, their links lead to all of them.
     */
    private const int LIST_SIZE = 5;

    #[Route(name: 'index')]
    public function index(): Response
    {
        $changes = (new Mergeable())->counts();

        return $this->render('dashboard/index.html.twig', [
            'openIssues' => $this->count('issue', [], [['terms' => ['status.name' => IssueStatusEnum::closedLabels()]]]),
            'openedIssues' => $this->count('issue', [['range' => ['created_on' => ['gte' => 'now-30d/d']]]]),
            'closedIssues' => $this->count('issue', [
                ['terms' => ['status.name' => IssueStatusEnum::closedLabels()]],
                ['range' => ['updated_on' => ['gte' => 'now-30d/d']]],
            ]),
            // The open issues created last, as the search lists them sorted by "Newest"
            'newIssues' => $this->latest('issue', IssueSearch::query(''), IssueSortEnum::NEWEST->sort()),
            // The changes ready to merge updated last, from the list "Ready to merge"
            'readyToMerge' => $this->latest('review', ReviewLists::query(ReviewLists::READY_TO_MERGE), [['updated_on' => 'desc']]),
            'changes' => $changes,
            'changeLabels' => Mergeable::LABELS,
            'changesTotal' => array_sum($changes),
            // Ready for review, but nobody touched them for a month
            'waitingChanges' => $this->count('review', [
                ['term' => ['reviewstatus' => ReviewStatusEnum::NEW->value]],
                ['range' => ['updated_on' => ['lt' => 'now-30d/d']]],
            ], [['term' => ['work_in_progress' => true]]]),
            'chartOptions' => ChartStyle::options(),
            'chartOptionsWithLegend' => ChartStyle::options(legend: true),
            'chartOptionsHorizontal' => ChartStyle::options(horizontal: true),
        ]);
    }

    /**
     * The first documents of a list, and how many the list has.
     *
     * @param array<string, mixed>       $query
     * @param list<array<string, mixed>> $sort
     *
     * @return array{documents: list<array<string, mixed>>, total: int}
     */
    private function latest(string $type, array $query, array $sort): array
    {
        $resultSet = ElasticUtility::getIndexByName($type)->createSearch([
            'query' => $query,
            'sort' => $sort,
            'size' => self::LIST_SIZE,
            'track_total_hits' => true,
        ])->search();

        return [
            'documents' => array_map(static fn ($result): array => $result->getData(), $resultSet->getResults()),
            'total' => $resultSet->getTotalHits(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $filter
     * @param list<array<string, mixed>> $mustNot
     */
    private function count(string $type, array $filter, array $mustNot = []): int
    {
        return ElasticUtility::getIndexByName($type)->count(['query' => ['bool' => ['filter' => $filter, 'must_not' => $mustNot]]]);
    }
}
