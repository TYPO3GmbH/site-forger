<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\CommandRun\CommandRunRecorder;
use App\DTO\CommandJobOverview;
use App\Entity\CommandRun;
use App\Entity\JobPause;
use App\Entity\QueuedJob;
use App\Enum\CommandRunStatusEnum;
use App\Repository\CommandRunRepository;
use App\Repository\JobPauseRepository;
use App\Repository\QueuedJobRepository;
use App\Scheduler\JobControl;
use App\Scheduler\ScheduledJobs;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the jobs of the schedule did and do, see App\Scheduler\JobSchedule and App\CommandRun\CommandRunRecorder,
 * and what admins do with them: run now, pause, resume, cancel, see App\Scheduler\JobControl. Other runs, like
 * app:elasticsearch:setup of a deployment, are recorded but not shown.
 */
#[IsGranted('ROLE_ADMIN')]
class CommandRunController extends AbstractController
{
    private const int LIST_LIMIT = 50;
    private const int MAX_LIST_LIMIT = 500;
    /**
     * The page reloads itself this often, to show the jobs start, run and finish.
     */
    private const int REFRESH_SECONDS = 15;
    /** While a run stops: it stops within seconds, see CommandRunRecorder::STOP_CHECK_INTERVAL */
    private const int REFRESH_SECONDS_STOPPING = 2;
    private const string CSRF_TOKEN_ID = 'command-jobs';

    public function __construct(
        private readonly CommandRunRepository $repository,
        private readonly ScheduledJobs $scheduledJobs,
        private readonly QueuedJobRepository $queue,
        private readonly JobPauseRepository $pauses,
        private readonly JobControl $control,
    ) {
    }

    #[Route(path: '/management/command-runs', name: 'management_command_runs')]
    public function index(Request $request): Response
    {
        $now = new \DateTimeImmutable();
        $scheduled = $this->scheduledJobs->jobs();
        $labels = array_map(static fn (array $job): string => $job['start']->label, $scheduled);
        $running = $this->repository->findRunning(array_keys($scheduled), $now);
        // What blocks a job: a running job with the same lock, by the job it blocks
        $runningLocks = [];
        foreach ($running as $run) {
            if (null !== ($lock = $scheduled[$run->getJob()]['start']->lock ?? null)) {
                $runningLocks[$lock] = $run->getJob();
            }
        }
        $blockedBy = [];
        foreach ($scheduled as $job => $scheduledJob) {
            $holder = null === $scheduledJob['start']->lock ? null : ($runningLocks[$scheduledJob['start']->lock] ?? null);
            if (null !== $holder && $holder !== $job) {
                $blockedBy[$job] = $holder;
            }
        }

        $recent = $this->repository->findRecentByJob($now->modify(CommandRunRecorder::RETENTION), array_keys($scheduled));
        $jobs = [];
        // In the order of the schedule, the jobs that need a look first
        foreach ($scheduled as $job => $scheduledJob) {
            if (isset($recent[$job])) {
                $jobs[] = new CommandJobOverview($job, $recent[$job], $now, $scheduledJob['trigger'], $blockedBy[$job] ?? null);
            }
        }
        usort($jobs, static fn (CommandJobOverview $a, CommandJobOverview $b): int => $b->needsAttention($now) <=> $a->needsAttention($now));
        $failedRuns = array_sum(array_map(static fn (CommandJobOverview $overview): int => $overview->failures, $jobs));
        // How long the running jobs usually take, by job
        $usualDurations = [];
        foreach ($jobs as $overview) {
            $usualDurations[$overview->job] = $overview->usualDuration();
        }

        $job = isset($scheduled[$request->query->getString('job')]) ? $request->query->getString('job') : null;
        $problemsOnly = $request->query->getBoolean('problems');
        $limit = $request->query->getInt('limit') >= self::MAX_LIST_LIMIT ? self::MAX_LIST_LIMIT : self::LIST_LIMIT;
        $runs = $this->repository->findLatest($limit, null === $job ? array_keys($scheduled) : [$job], $problemsOnly ? CommandRunStatusEnum::problems() : null);
        if ($problemsOnly) {
            // A stored "running" is a problem only once it stopped sending heartbeats
            $runs = array_values(array_filter($runs, static fn (CommandRun $run): bool => CommandRunStatusEnum::RUNNING !== $run->getStatus($now)));
        }

        // What runs next: the queued jobs, then the next run of every job of the schedule
        $pauses = $this->pauses->findPauses();
        $queued = $this->queue->findQueued();
        $next = [];
        foreach ($scheduled as $name => $scheduledJob) {
            $next[] = ['job' => $name, 'at' => $scheduledJob['trigger']->getNextRunDate($now), 'schedule' => (string) $scheduledJob['trigger']];
        }
        usort($next, static fn (array $a, array $b): int => [$a['at'], $a['job']] <=> [$b['at'], $b['job']]);

        return $this->render('management/commands/index.html.twig', [
            'jobs' => $jobs,
            'labels' => $labels,
            'queued' => $queued,
            'queuedJobs' => array_map(static fn (QueuedJob $queuedJob): string => $queuedJob->getJob(), $queued),
            'runningJobs' => array_map(static fn (CommandRun $run): string => $run->getJob(), $running),
            'blockedBy' => $blockedBy,
            'pauseAll' => $pauses[JobPause::ALL] ?? null,
            'pauses' => $pauses,
            'next' => $next,
            'jobsToken' => self::CSRF_TOKEN_ID,
            'overdue' => array_values(array_filter($jobs, static fn (CommandJobOverview $overview): bool => $overview->overdue)),
            'running' => $running,
            'usualDurations' => $usualDurations,
            'steps' => $this->scheduledJobs->steps(),
            'failedRuns' => $failedRuns,
            'job' => $job,
            'problemsOnly' => $problemsOnly,
            'runs' => $runs,
            'now' => $now,
            'retention' => ltrim(CommandRunRecorder::RETENTION, '-'),
            'refreshSeconds' => [] !== array_filter($running, static fn (CommandRun $run): bool => $run->isStopping()) ? self::REFRESH_SECONDS_STOPPING : self::REFRESH_SECONDS,
            'limit' => $limit,
            'maxLimit' => self::MAX_LIST_LIMIT,
        ]);
    }

    /**
     * Queues a job of the schedule to run now: the worker of the schedule starts it within a minute, see
     * App\Scheduler\StartQueuedJobsHandler. Not the request itself: it is no console, and an import would run
     * in the web server.
     */
    #[Route(path: '/management/command-runs/queue', name: 'management_command_runs_queue', methods: ['POST'])]
    public function queue(Request $request): Response
    {
        $job = $this->job($request);
        if (null === $job) {
            return $this->back($request);
        }
        $label = $this->scheduledJobs->labels()[$job];
        if ($this->queue->queue($job, sprintf('Run now by %s', $this->user()), new \DateTimeImmutable())) {
            $this->addFlash('success', sprintf('"%s" is queued and starts within a minute.', $label));
        } else {
            $this->addFlash('info', sprintf('"%s" is queued already.', $label));
        }

        return $this->back($request);
    }

    /**
     * Pauses a job, or all of them without one: a running one stops at its next heartbeat and starts again once
     * resumed.
     */
    #[Route(path: '/management/command-runs/pause', name: 'management_command_runs_pause', methods: ['POST'])]
    public function pause(Request $request): Response
    {
        $all = '' === $request->getPayload()->getString('job');
        $job = $all ? null : $this->job($request);
        if ((!$all && null === $job) || ($all && !$this->tokenValid($request))) {
            return $this->back($request);
        }
        $stopping = $this->control->pause($job, $this->user(), sprintf('paused by %s', $this->user()), new \DateTimeImmutable());
        $this->addFlash('success', sprintf(
            '%s paused%s.',
            null === $job ? 'All jobs are' : sprintf('"%s" is', $this->scheduledJobs->labels()[$job]),
            [] === $stopping ? '' : ', the running one stops within a few seconds and starts again once resumed',
        ));

        return $this->back($request);
    }

    #[Route(path: '/management/command-runs/resume', name: 'management_command_runs_resume', methods: ['POST'])]
    public function resume(Request $request): Response
    {
        $all = '' === $request->getPayload()->getString('job');
        $job = $all ? null : $this->job($request);
        if ((!$all && null === $job) || ($all && !$this->tokenValid($request))) {
            return $this->back($request);
        }
        $this->control->resume($job);
        $this->addFlash('success', sprintf('%s resumed, what waits starts within a minute.', null === $job ? 'All jobs are' : sprintf('"%s" is', $this->scheduledJobs->labels()[$job])));

        return $this->back($request);
    }

    /**
     * Stops a running job, not to start again.
     */
    #[Route(path: '/management/command-runs/{id}/cancel', name: 'management_command_runs_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(Request $request, int $id): Response
    {
        if ($this->tokenValid($request)) {
            $this->addFlash(...$this->control->cancel($id, $this->user())
                ? ['success', 'The run stops within a few seconds.']
                : ['info', 'The run ended already, or stops already.']);
        }

        return $this->back($request);
    }

    /**
     * The job of the form, if the form is valid and the job one of the schedule.
     */
    private function job(Request $request): ?string
    {
        if (!$this->tokenValid($request)) {
            return null;
        }
        $job = $request->getPayload()->getString('job');
        if (!isset($this->scheduledJobs->labels()[$job])) {
            $this->addFlash('danger', 'That is no job of the schedule.');

            return null;
        }

        return $job;
    }

    private function tokenValid(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            return true;
        }
        $this->addFlash('danger', 'The form expired, please try again.');

        return false;
    }

    private function user(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? 'unknown';
    }

    /**
     * Back to the command runs page with the messages of the action. The page posts in the background (see
     * assets/js/command-runs.js): it gets the messages as JSON to show as notices, and updates itself in place.
     */
    private function back(Request $request): Response
    {
        if ($request->isXmlHttpRequest()) {
            $messages = [];
            $session = $request->getSession();
            $flashes = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->all() : [];
            foreach ($flashes as $type => $texts) {
                foreach ($texts as $text) {
                    $messages[] = ['type' => $type, 'text' => (string) $text];
                }
            }

            return new JsonResponse(['messages' => $messages]);
        }

        return $this->redirectToRoute('management_command_runs');
    }
}
