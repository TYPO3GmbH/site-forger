<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Scheduler;

use App\Entity\CommandRun;
use App\Entity\JobPause;
use App\Enum\CommandRunStatusEnum;
use App\Repository\CommandRunRepository;
use App\Repository\JobPauseRepository;
use App\Repository\QueuedJobRepository;

/**
 * Pauses and resumes the jobs of the schedule, one or all of them, and cancels a run: for the command runs page
 * and app:jobs:pause / app:jobs:resume, which a deployment runs.
 *
 * While a job is paused it does not start: its planned starts wait in the queue (StartJobHandler), the queue
 * leaves it (StartQueuedJobsHandler). A paused job that runs is asked to stop: it stops at its next heartbeat
 * as "Interrupted" and is queued, so it starts again once resumed. Starting again costs little, a delta goes on
 * from the index, a full import skips what it has. A cancelled run is not queued.
 */
final readonly class JobControl
{
    public function __construct(
        private JobPauseRepository $pauses,
        private QueuedJobRepository $queue,
        private CommandRunRepository $runs,
        private ScheduledJobs $scheduledJobs,
    ) {
    }

    /**
     * Pauses a job of the schedule, or all of them without one.
     *
     * @param string|null $job the job as its runs are recorded, null for all
     *
     * @return list<CommandRun> the runs asked to stop, see waitUntilStopped()
     *
     * @throws \InvalidArgumentException for a job that is not in the schedule
     */
    public function pause(?string $job, string $pausedBy, string $reason, \DateTimeImmutable $now): array
    {
        $jobs = $this->jobs($job);
        $this->pauses->pause($job ?? JobPause::ALL, $pausedBy, $reason, $now);
        $stopping = [];
        foreach ($this->runs->findRunning($jobs, $now) as $run) {
            $this->runs->requestStop((int) $run->getId(), CommandRunStatusEnum::INTERRUPTED, sprintf('Interrupted: %s', $reason));
            // To start again once resumed
            $this->queue->queue($run->getJob(), sprintf('interrupted, %s', $reason), $now);
            $stopping[] = $run;
        }

        return $stopping;
    }

    /**
     * Ends the pause of a job, or of all of them: the queue starts what waits within a minute.
     *
     * @return bool whether it was paused
     */
    public function resume(?string $job): bool
    {
        $this->jobs($job);

        return $this->pauses->resume($job ?? JobPause::ALL);
    }

    /**
     * Asks a run to stop, not to start again.
     */
    public function cancel(int $runId, string $cancelledBy): bool
    {
        return $this->runs->requestStop($runId, CommandRunStatusEnum::CANCELLED, sprintf('Cancelled by %s', $cancelledBy));
    }

    /**
     * The pause a job is in, its own or that of all jobs, null if it may run.
     *
     * @param array<string, JobPause>|null $pauses see JobPauseRepository::findPauses(), read if not given
     */
    public function pauseOf(string $job, ?array $pauses = null): ?JobPause
    {
        $pauses ??= $this->pauses->findPauses();

        return $pauses[JobPause::ALL] ?? $pauses[$job] ?? null;
    }

    /**
     * Waits for runs asked to stop, as long as it takes them but at most $seconds.
     *
     * @param list<CommandRun>    $runs
     * @param \Closure(int): void $sleep sleeps the seconds given, to wait
     *
     * @return list<CommandRun> those still running after the wait
     */
    public function waitUntilStopped(array $runs, int $seconds, \Closure $sleep): array
    {
        for ($waited = 0;; $waited += 2) {
            $runs = array_values(array_filter($runs, fn (CommandRun $run): bool => $this->runs->isStillRunning((int) $run->getId(), new \DateTimeImmutable())));
            if ([] === $runs || $waited >= $seconds) {
                return $runs;
            }
            $sleep(2);
        }
    }

    /**
     * @return list<string> the job, or every job of the schedule without one
     */
    private function jobs(?string $job): array
    {
        $scheduled = array_keys($this->scheduledJobs->triggers());
        if (null !== $job && !in_array($job, $scheduled, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is no job of the schedule, these are: "%s"', $job, implode('", "', $scheduled)));
        }

        return null === $job ? $scheduled : [$job];
    }
}
