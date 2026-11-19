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

/**
 * The state of a job (a command with its options) from its recent runs: when it last ran, when it last
 * did its work, and whether that is longer ago than usual.
 *
 * "Usual" is learned from the runs, there is no schedule to configure: the median gap between the
 * starts of the latest runs. A job is overdue when its last start or its last successful run is more
 * than OVERDUE_FACTOR gaps ago (at least MIN_GRACE seconds), e.g. because cron no longer starts it
 * or every run fails. A run skipped for a lock counts as fine for up to MAX_LOCKED seconds: another run
 * does the work (e.g. reviewFull blocks reviewDelta for hours). Without a successful run for longer the
 * job is overdue all the same, e.g. when two jobs share a lock they should not.
 */
final readonly class CommandJobOverview
{
    private const int OVERDUE_FACTOR = 3;
    private const int MIN_GRACE = 900;
    private const int SAMPLE = 10;
    private const int MAX_LOCKED = 43200;

    public ?CommandRun $lastSuccess;
    /** Seconds, null with fewer than three runs */
    public ?int $interval;
    public bool $overdue;
    public int $failures;

    /**
     * @param list<CommandRun> $runs newest first, at least one
     */
    public function __construct(
        public string $job,
        public array $runs,
        \DateTimeImmutable $now,
    ) {
        $this->lastSuccess = array_find($runs, static fn (CommandRun $run): bool => $run->getStatus($now)->isSuccessful());
        $this->failures = count(array_filter($runs, static fn (CommandRun $run): bool => in_array($run->getStatus($now), [CommandRunStatusEnum::FAILED, CommandRunStatusEnum::ABORTED], true)));
        $this->interval = self::medianGap(array_slice($runs, 0, self::SAMPLE));

        $overdue = false;
        if (null !== $this->interval) {
            $limit = max(self::OVERDUE_FACTOR * $this->interval, $this->interval + self::MIN_GRACE);
            $sinceStart = $now->getTimestamp() - $this->lastRun()->getStartedAt()->getTimestamp();
            $lastFine = array_find($runs, static fn (CommandRun $run): bool => $run->getStatus($now)->isSuccessful() || CommandRunStatusEnum::LOCKED === $run->getStatus($now));
            $sinceFine = null === $lastFine ? PHP_INT_MAX : $now->getTimestamp() - $lastFine->getStartedAt()->getTimestamp();
            $sinceSuccess = null === $this->lastSuccess ? PHP_INT_MAX : $now->getTimestamp() - $this->lastSuccess->getStartedAt()->getTimestamp();
            $overdue = $sinceStart > $limit || $sinceFine > $limit || $sinceSuccess > max($limit, self::MAX_LOCKED);
        }
        $this->overdue = $overdue;
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

    public function lastRun(): CommandRun
    {
        return $this->runs[0];
    }

    /**
     * @param list<CommandRun> $runs newest first
     */
    private static function medianGap(array $runs): ?int
    {
        $gaps = [];
        for ($i = 1, $count = count($runs); $i < $count; ++$i) {
            $gaps[] = $runs[$i - 1]->getStartedAt()->getTimestamp() - $runs[$i]->getStartedAt()->getTimestamp();
        }
        if (count($gaps) < 2) {
            return null;
        }
        sort($gaps);

        return $gaps[intdiv(count($gaps), 2)];
    }
}
