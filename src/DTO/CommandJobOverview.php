<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\DTO;

use App\Entity\CommandRun;
use App\Enum\CommandRunStatusEnum;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

/**
 * The state of a job (a command with its options) from its recent runs: when it last ran, when it last
 * did its work, and whether a run the schedule planned is missing, see App\Scheduler\JobSchedule.
 *
 * A job of the schedule is overdue when the run planned after its last start did not start (GRACE seconds
 * after it was due), e.g. because cron no longer starts the worker of the schedule, or when three planned runs
 * in a row did not do their work, e.g. because every run fails. A run skipped for a lock counts as fine for up
 * to MAX_LOCKED seconds: another run does the work (e.g. reviewFull blocks reviewDelta for hours). Without a
 * successful run for longer the job is overdue all the same.
 */
final readonly class CommandJobOverview
{
    private const int GRACE = 600;
    private const int MISSED_RUNS = 3;
    private const int MAX_LOCKED = 43200;

    public ?CommandRun $lastSuccess;
    /** When the schedule runs the job, like "every 5 minutes" */
    public string $schedule;
    public ?\DateTimeImmutable $nextRun;
    public bool $overdue;
    public int $failures;

    /**
     * @param list<CommandRun> $runs      newest first, at least one
     * @param TriggerInterface $trigger   when the schedule runs the job, see App\Scheduler\ScheduledJobs
     * @param string|null      $blockedBy the job running with the same lock, the job waits for it and is not overdue
     */
    public function __construct(
        public string $job,
        public array $runs,
        \DateTimeImmutable $now,
        TriggerInterface $trigger,
        public ?string $blockedBy = null,
    ) {
        $this->lastSuccess = array_find($runs, static fn (CommandRun $run): bool => $run->getStatus($now)->isSuccessful());
        $this->failures = count(array_filter($runs, static fn (CommandRun $run): bool => in_array($run->getStatus($now), [CommandRunStatusEnum::FAILED, CommandRunStatusEnum::ABORTED], true)));
        $this->schedule = (string) $trigger;
        $this->nextRun = $trigger->getNextRunDate($now);

        $late = static fn (?\DateTimeImmutable $due): bool => null === $due || $now->getTimestamp() > $due->getTimestamp() + self::GRACE;
        // Without a fine or successful run among the runs kept, counted from the first of them: a job that ran
        // for the first time is not overdue for that
        $first = $runs[array_key_last($runs)]->getStartedAt();
        $lastFine = array_find($runs, static fn (CommandRun $run): bool => $run->getStatus($now)->isSuccessful() || $run->getStatus($now)->isStoppedOnPurpose());
        $sinceSuccess = $this->lastSuccess?->getStartedAt() ?? $first;
        // A running job does its work, a blocked one waits for the job doing it
        $this->overdue = null === $blockedBy && CommandRunStatusEnum::RUNNING !== $this->lastRun()->getStatus($now) && (
            $late(self::planned($trigger, $this->lastRun()->getStartedAt(), 1))
            || $late(self::planned($trigger, $lastFine?->getStartedAt() ?? $first, self::MISSED_RUNS))
            || $late(max(self::planned($trigger, $sinceSuccess, self::MISSED_RUNS), $sinceSuccess->modify('+' . self::MAX_LOCKED . ' seconds')))
        );
    }

    /**
     * Overdue, or the last run failed or was aborted.
     */
    public function needsAttention(\DateTimeImmutable $now): bool
    {
        return $this->overdue || in_array($this->lastRun()->getStatus($now), [CommandRunStatusEnum::FAILED, CommandRunStatusEnum::ABORTED], true);
    }

    /**
     * The latest runs, oldest first, for the history strip.
     *
     * @return list<CommandRun>
     */
    public function history(int $count = 30): array
    {
        return array_reverse(array_slice($this->runs, 0, $count));
    }

    /**
     * How long the job takes, as its last successful run took, null without one: for the expected end of a run.
     */
    public function usualDuration(): ?int
    {
        return $this->lastSuccess?->getDuration();
    }

    public function lastRun(): CommandRun
    {
        return $this->runs[0];
    }

    /**
     * The run the schedule plans as the $count-th after a time.
     */
    private static function planned(TriggerInterface $trigger, \DateTimeImmutable $after, int $count): ?\DateTimeImmutable
    {
        $run = $after;
        for ($i = 0; $i < $count && null !== $run; ++$i) {
            $run = $trigger->getNextRunDate($run);
        }

        return $run;
    }
}
