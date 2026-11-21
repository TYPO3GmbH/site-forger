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
use App\Report\KeyFigures;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/', name: 'dashboard_')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly KeyFigures $keyFigures,
        private readonly Mergeable $mergeable,
    ) {
    }

    #[Route(name: 'index')]
    public function index(): Response
    {
        $changes = $this->mergeable->counts();
        $today = new \DateTimeImmutable();

        return $this->render('dashboard/index.html.twig', [
            'openIssues' => $this->keyFigures->openIssues(),
            'openedIssues' => $this->keyFigures->openedIssues(),
            'closedIssues' => $this->keyFigures->closedIssues(),
            'openRegressions' => $this->keyFigures->openRegressions(),
            'newIssues' => $this->keyFigures->newIssues(),
            'readyToMerge' => $this->keyFigures->readyToMerge(),
            'changes' => $changes,
            'changeLabels' => Mergeable::LABELS,
            'changesTotal' => array_sum($changes),
            'waitingChanges' => $this->keyFigures->waitingChanges($today),
            'waitingFilter' => KeyFigures::waitingFilter($today),
            'chartOptions' => ChartStyle::options(),
            'chartOptionsWithLegend' => ChartStyle::options(legend: true),
            'chartOptionsHorizontal' => ChartStyle::options(horizontal: true),
        ]);
    }
}
