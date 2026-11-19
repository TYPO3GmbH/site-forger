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
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStatusEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/', name: 'dashboard_')]
class DashboardController extends AbstractController
{
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
     * @param list<array<string, mixed>> $filter
     * @param list<array<string, mixed>> $mustNot
     */
    private function count(string $type, array $filter, array $mustNot = []): int
    {
        return ElasticUtility::getIndexByName($type)->count(['query' => ['bool' => ['filter' => $filter, 'must_not' => $mustNot]]]);
    }
}
