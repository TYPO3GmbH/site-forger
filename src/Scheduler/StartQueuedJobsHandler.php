<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Scheduler;

use App\Repository\JobPauseRepository;
use App\Repository\QueuedJobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Starts the jobs queued on the command runs page, as the schedule starts them, see StartJobHandler. A queued
 * job is taken from the queue first, so two workers do not start it twice. A job that is no longer in the
 * schedule is dropped.
 *
 * A paused job stays queued until it is resumed, see JobControl. A queued job stays queued, to start a minute
 * later, while it would only find its lock taken: while a job with
 * the same lock runs (a delta, reviewFull for hours), or one is planned for this minute, the job itself too (its
 * planned start takes it from the queue, whichever of the two messages the worker handles first).
 */
#[AsMessageHandler]
final readonly class StartQueuedJobsHandler
{
    public function __construct(
        private QueuedJobRepository $queue,
        private ScheduledJobs $scheduledJobs,
        private StartJobHandler $startJob,
        private JobLocks $locks,
        private JobControl $control,
        private JobPauseRepository $pauses,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StartQueuedJobs $message): void
    {
        $this->start(new \DateTimeImmutable());
    }

    public function start(\DateTimeImmutable $now): void
    {
        $queued = $this->queue->findQueued();
        if ([] === $queued) {
            return;
        }
        $jobs = $this->scheduledJobs->jobs();
        $pauses = $this->pauses->findPauses();
        $minute = $now->setTime((int) $now->format('H'), (int) $now->format('i'));
        // The locks of the jobs planned for this minute
        $plannedLocks = [];
        foreach ($jobs as $name => $scheduled) {
            $planned = $scheduled['trigger']->getNextRunDate($minute->modify('-1 second'));
            if (null !== $planned && $planned <= $now) {
                $plannedLocks[$scheduled['start']->lock ?? $name] = true;
            }
        }
        foreach ($queued as $job) {
            if (null !== $this->control->pauseOf($job->getJob(), $pauses)) {
                continue;
            }
            $lock = isset($jobs[$job->getJob()]) ? ($jobs[$job->getJob()]['start']->lock ?? $job->getJob()) : null;
            if (null !== $lock && (isset($plannedLocks[$lock]) || (null !== $jobs[$job->getJob()]['start']->lock && !$this->locks->isFree($lock)))) {
                continue;
            }
            if (!$this->queue->claim($job)) {
                continue;
            }
            if (!isset($jobs[$job->getJob()])) {
                $this->logger->warning(sprintf('Dropped the queued job "%s", it is not in the schedule', $job->getJob()));
                continue;
            }
            $this->logger->info(sprintf('Starting the job "%s" queued by %s', $job->getJob(), $job->getQueuedBy()));
            ($this->startJob)($jobs[$job->getJob()]['start']);
        }
    }
}
