<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\DTO\CommandJobOverview;
use App\Entity\CommandRun;
use App\Enum\CommandRunStatusEnum;
use App\Repository\CommandRunRepository;
use App\Service\CommandRunRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the cron jobs did: the imports and the other "app:*" commands, see App\Service\CommandRunRecorder.
 */
#[IsGranted('ROLE_ADMIN')]
class CommandRunController extends AbstractController
{
    private const int LIST_LIMIT = 50;
    private const int MAX_LIST_LIMIT = 500;
    /**
     * While a command runs, the page reloads itself this often to show its progress.
     */
    private const int REFRESH_SECONDS = 15;

    public function __construct(
        private readonly CommandRunRepository $repository,
    ) {
    }

    #[Route(path: '/management/command-runs', name: 'management_command_runs')]
    public function index(Request $request): Response
    {
        $now = new \DateTimeImmutable();
        $jobs = [];
        foreach ($this->repository->findRecentByJob($now->modify(CommandRunRecorder::RETENTION)) as $job => $runs) {
            $jobs[] = new CommandJobOverview((string) $job, $runs, $now);
        }
        // Jobs that need a look first
        usort($jobs, static fn (CommandJobOverview $a, CommandJobOverview $b): int => [$b->needsAttention($now), $a->job] <=> [$a->needsAttention($now), $b->job]);

        $job = '' === $request->query->getString('job') ? null : $request->query->getString('job');
        $problemsOnly = $request->query->getBoolean('problems');
        $limit = $request->query->getInt('limit') >= self::MAX_LIST_LIMIT ? self::MAX_LIST_LIMIT : self::LIST_LIMIT;
        $runs = $this->repository->findLatest($limit, $job, $problemsOnly ? CommandRunStatusEnum::problems() : null);
        if ($problemsOnly) {
            // A stored "running" is a problem only once it stopped sending heartbeats
            $runs = array_values(array_filter($runs, static fn (CommandRun $run): bool => CommandRunStatusEnum::RUNNING !== $run->getStatus($now)));
        }
        $running = [];
        $failedRuns = 0;
        foreach ($jobs as $overview) {
            $failedRuns += $overview->failures;
            if (CommandRunStatusEnum::RUNNING === $overview->lastRun()->getStatus($now)) {
                $running[] = $overview->lastRun();
            }
        }

        return $this->render('management/commands/index.html.twig', [
            'jobs' => $jobs,
            'overdue' => array_values(array_filter($jobs, static fn (CommandJobOverview $overview): bool => $overview->overdue)),
            'running' => $running,
            'failedRuns' => $failedRuns,
            'job' => $job,
            'problemsOnly' => $problemsOnly,
            'runs' => $runs,
            'now' => $now,
            'retention' => ltrim(CommandRunRecorder::RETENTION, '-'),
            'refreshSeconds' => self::REFRESH_SECONDS,
            'limit' => $limit,
            'maxLimit' => self::MAX_LIST_LIMIT,
        ]);
    }
}
