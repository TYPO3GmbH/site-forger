<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Enum\StreamFigureEnum;
use App\Enum\StreamRangeEnum;
use App\Report\KeyFigures;
use App\Search\StreamFilter;
use App\Search\StreamWindow;
use App\Stream\StreamStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/', name: 'dashboard_')]
class DashboardController extends AbstractController
{
    /**
     * The new contributors the dashboard shows: as many as StreamController::DASHBOARD_COUNT, for cards of one height.
     */
    public const int NEWCOMERS = 6;

    public function __construct(
        private readonly KeyFigures $keyFigures,
        private readonly StreamStatistics $statistics,
    ) {
    }

    /**
     * Where work waits: the key figures and the short lists of what needs attention. The charts are on the page of
     * statistics, for a look now and then.
     */
    #[Route(name: 'index')]
    public function index(): Response
    {
        $changes = $this->keyFigures->openChanges();
        $today = new \DateTimeImmutable();
        // The same 30 days as on the page of statistics, so the numbers match there
        $month = $this->statistics->counts(
            new StreamFilter(),
            StreamWindow::ofRange(StreamRangeEnum::MONTH, StreamWindow::now($today->getTimezone())),
        );

        return $this->render('dashboard/index.html.twig', [
            'openIssues' => $this->keyFigures->openIssues(),
            'openedIssues' => $month[StreamFigureEnum::ISSUES_CREATED->value],
            'resolvedIssues' => $month[StreamFigureEnum::ISSUES_RESOLVED->value],
            'statisticsRange' => StreamRangeEnum::MONTH->value,
            'newIssues' => $this->keyFigures->newIssues(),
            'readyToMerge' => $this->keyFigures->readyToMerge(),
            'regressions' => $this->keyFigures->regressions(),
            'recentlyMerged' => $this->keyFigures->recentlyMerged(),
            'changes' => $changes,
            'changeLabels' => KeyFigures::CHANGE_STATES,
            'changesTotal' => array_sum($changes),
            'waitingChanges' => $this->keyFigures->waitingChanges($today),
            'waitingFilter' => KeyFigures::waitingFilter($today),
        ]);
    }

    /**
     * The statistics: the board of the activity, see templates/stream/_frame.html.twig.
     */
    #[Route(path: 'statistics', name: 'statistics', methods: ['GET'])]
    public function statistics(): Response
    {
        return $this->render('dashboard/statistics.html.twig');
    }
}
