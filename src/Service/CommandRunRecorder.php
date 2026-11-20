<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Service;

use App\Enum\CommandRunStatusEnum;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

/**
 * Records the runs of console commands in "command_run", so admins see what the cron jobs did.
 *
 * App\Subscriber\CommandRunSubscriber starts and finishes a run, the commands add what they did:
 * written documents, skipped items, a lock held by another run.
 *
 * Recording never breaks a command: database errors are logged and the command goes on.
 * Imports run for hours, longer than the database keeps an idle connection open, so every write
 * reconnects once if the connection is gone.
 */
final class CommandRunRecorder
{
    /**
     * Limits that keep the table small whatever the cron jobs do: one row per run, runs older than
     * RETENTION and all but the latest MAX_RUNS_PER_JOB runs of a job are deleted when a run starts,
     * the message holds the error and the first warnings only, shortened.
     */
    public const string RETENTION = '-14 days';
    public const int MAX_RUNS_PER_JOB = 500;
    public const int MAX_WARNING_MESSAGES = 5;
    private const int MAX_WARNING_LENGTH = 300;
    private const int MAX_ERROR_LENGTH = 1000;
    private const int MAX_MESSAGE_LENGTH = 2000;
    private const int HEARTBEAT_INTERVAL = 15;
    /**
     * How often a run reads whether it was asked to stop, more often than it writes its heartbeat: a cancel on the
     * command runs page takes effect within seconds. Reading the row by its id costs little.
     */
    private const int STOP_CHECK_INTERVAL = 2;

    private ?int $runId = null;
    private int $documents = 0;
    private int $warnings = 0;
    /** @var list<string> */
    private array $warningMessages = [];
    private ?string $error = null;
    private bool $locked = false;
    private ?string $phase = null;
    private ?int $progressCurrent = null;
    private ?int $progressTotal = null;
    /**
     * The steps of the run so far, in the order they started: a step the command comes back to keeps its start,
     * so the speed of the page counts the time in between in. Written as "steps", see CommandRun::steps().
     *
     * @var list<array{name: string, startedAt: \DateTimeImmutable, endedAt: ?\DateTimeImmutable, current: ?int, total: ?int}>
     */
    private array $steps = [];
    /** What the current step waits for, a rate limit for example, until it goes on */
    private ?string $waiting = null;
    private int $lastHeartbeat = 0;
    private int $lastStopCheck = 0;
    /** The status and the reason the run was asked to stop with, see heartbeat() */
    private ?CommandRunStatusEnum $stoppedAs = null;
    private ?string $stopReason = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function start(string $job): void
    {
        if (null !== $this->runId) {
            // A command started by another command belongs to the run of the outer one
            return;
        }
        $this->documents = 0;
        $this->warnings = 0;
        $this->warningMessages = [];
        $this->error = null;
        $this->locked = false;
        $this->phase = null;
        $this->progressCurrent = null;
        $this->progressTotal = null;
        $this->steps = [];
        $this->waiting = null;
        $this->stoppedAs = null;
        $this->stopReason = null;
        $this->lastHeartbeat = time();
        $this->lastStopCheck = time();
        $now = new \DateTimeImmutable();

        $job = mb_substr($job, 0, 255);
        $this->write(function () use ($job, $now): void {
            $this->prune($job, $now);
            $this->connection->insert('command_run', [
                'job' => $job,
                'status' => CommandRunStatusEnum::RUNNING->value,
                'started_at' => $now,
                'heartbeat_at' => $now,
                'documents' => 0,
                'warnings' => 0,
                'host' => mb_substr((string) gethostname(), 0, 255),
                'pid' => (int) getmypid(),
            ], [
                'started_at' => Types::DATETIME_IMMUTABLE,
                'heartbeat_at' => Types::DATETIME_IMMUTABLE,
            ]);
            $this->runId = (int) $this->connection->lastInsertId();
        });
    }

    public function isRecording(): bool
    {
        return null !== $this->runId;
    }

    /**
     * A document was written. Updates the run now and then, so its progress shows while it runs.
     */
    public function document(int $count = 1): void
    {
        $this->documents += $count;
        $this->heartbeat();
    }

    /**
     * The step the command is in and how far it got, shown while it runs. Updates the run now and then.
     *
     * A new step ends the one before. The names of the steps of a job of the schedule are declared in
     * App\Scheduler\JobSchedule::JOBS, so the page shows the steps still to come as well.
     */
    public function progress(?string $phase, ?int $current = null, ?int $total = null): void
    {
        $phase = null === $phase ? null : self::shorten($phase, 255);
        $phaseChanged = $phase !== $this->phase;
        $now = new \DateTimeImmutable();
        if ($phaseChanged) {
            $this->endStep($now);
            if (null !== $phase) {
                $this->startStep($phase, $now);
            }
        }
        $this->phase = $phase;
        $this->progressCurrent = $current;
        $this->progressTotal = $total;
        $this->waiting = null;
        if (null !== $phase) {
            $last = array_key_last($this->steps);
            $this->steps[$last]['current'] = $current;
            $this->steps[$last]['total'] = $total;
        }
        // A new step shows right away
        $this->heartbeat($phaseChanged);
    }

    /**
     * The current step waits, for a rate limit for example: shown at once, until the step goes on.
     */
    public function waiting(string $reason): void
    {
        $this->waiting = self::shorten($reason, 255);
        $this->heartbeat(true);
    }

    /**
     * Sleeps, for a rate limit for example, a second at a time: a run asked to stop meanwhile stops within seconds
     * instead of after the wait.
     *
     * @param \Closure(int): void|null $sleep sleeps the seconds given, sleep() if not given
     *
     * @throws JobStopped
     */
    public function sleep(int $seconds, ?\Closure $sleep = null): void
    {
        $sleep ??= static function (int $seconds): void {
            sleep($seconds);
        };
        for ($slept = 1; $slept <= $seconds; ++$slept) {
            $sleep(1);
            $this->heartbeat();
            // By the seconds slept, the clock may stand still for a sleep of a test
            if (null !== $this->runId && 0 === $slept % self::STOP_CHECK_INTERVAL) {
                $this->stopIfAsked();
            }
        }
    }

    /**
     * An item was skipped because of an error, the command goes on.
     */
    public function warning(string $message): void
    {
        ++$this->warnings;
        if (count($this->warningMessages) < self::MAX_WARNING_MESSAGES) {
            $this->warningMessages[] = self::shorten($message, self::MAX_WARNING_LENGTH);
        }
        $this->heartbeat();
    }

    /**
     * The error that ends the command.
     */
    public function fail(string $message): void
    {
        $this->error = self::shorten($message, self::MAX_ERROR_LENGTH);
    }

    /**
     * The command did nothing, another run holds the lock.
     */
    public function locked(): void
    {
        $this->locked = true;
    }

    public function finish(int $exitCode): void
    {
        if (null === $this->runId) {
            return;
        }
        $status = match (true) {
            null !== $this->stoppedAs => $this->stoppedAs,
            0 !== $exitCode || null !== $this->error => CommandRunStatusEnum::FAILED,
            $this->locked => CommandRunStatusEnum::LOCKED,
            $this->warnings > 0 => CommandRunStatusEnum::WARNING,
            default => CommandRunStatusEnum::SUCCESS,
        };
        $now = new \DateTimeImmutable();
        $this->endStep($now);
        $this->waiting = null;
        $this->write(fn (): int => $this->connection->update('command_run', [
            'status' => $status->value,
            'heartbeat_at' => $now,
            'finished_at' => $now,
            'exit_code' => $exitCode,
            'phase' => $this->phase,
            'phase_started_at' => $this->phaseStartedAt(),
            'progress_current' => $this->progressCurrent,
            'progress_total' => $this->progressTotal,
            'steps' => $this->stepsJson(),
            'documents' => $this->documents,
            'warnings' => $this->warnings,
            'message' => $this->message(),
        ], ['id' => $this->runId], [
            'heartbeat_at' => Types::DATETIME_IMMUTABLE,
            'finished_at' => Types::DATETIME_IMMUTABLE,
            'phase_started_at' => Types::DATETIME_IMMUTABLE,
        ]));
        $this->runId = null;
    }

    /**
     * Writes how far the command got, and stops it if it was asked to, see JobStopped. Between two heartbeats it
     * only reads whether it was asked to stop, every STOP_CHECK_INTERVAL seconds.
     *
     * @throws JobStopped
     */
    private function heartbeat(bool $now = false): void
    {
        if (null === $this->runId) {
            return;
        }
        if (!$now && time() - $this->lastHeartbeat < self::HEARTBEAT_INTERVAL) {
            if (time() - $this->lastStopCheck >= self::STOP_CHECK_INTERVAL) {
                $this->stopIfAsked();
            }

            return;
        }
        $this->lastHeartbeat = time();
        $this->write(fn (): int => $this->connection->update('command_run', [
            'heartbeat_at' => new \DateTimeImmutable(),
            'phase' => $this->phase,
            'phase_started_at' => $this->phaseStartedAt(),
            'progress_current' => $this->progressCurrent,
            'progress_total' => $this->progressTotal,
            'steps' => $this->stepsJson(),
            'documents' => $this->documents,
            'warnings' => $this->warnings,
            'message' => $this->message(),
        ], ['id' => $this->runId], [
            'heartbeat_at' => Types::DATETIME_IMMUTABLE,
            'phase_started_at' => Types::DATETIME_IMMUTABLE,
        ]));
        $this->stopIfAsked();
    }

    /**
     * @throws JobStopped
     */
    private function stopIfAsked(): void
    {
        if (null !== $this->stoppedAs) {
            return;
        }
        $this->lastStopCheck = time();
        $stop = null;
        $this->write(function () use (&$stop): void {
            $stop = $this->connection->fetchAssociative('SELECT stop_status, stop_reason FROM command_run WHERE id = ?', [$this->runId]);
        });
        $status = is_array($stop) && null !== $stop['stop_status'] ? CommandRunStatusEnum::tryFrom((string) $stop['stop_status']) : null;
        if (null === $status) {
            return;
        }
        $this->stoppedAs = $status;
        $this->stopReason = (string) ($stop['stop_reason'] ?? '');
        throw new JobStopped('' === $this->stopReason ? 'Asked to stop' : $this->stopReason);
    }

    private function phaseStartedAt(): ?\DateTimeImmutable
    {
        foreach ($this->steps as $step) {
            if ($step['name'] === $this->phase) {
                return $step['startedAt'];
            }
        }

        return null;
    }

    /**
     * Starts a step, or goes on with it if the run was in it before: it keeps its start and moves to the end.
     */
    private function startStep(string $name, \DateTimeImmutable $now): void
    {
        $startedAt = $now;
        foreach ($this->steps as $position => $step) {
            if ($step['name'] === $name) {
                $startedAt = $step['startedAt'];
                unset($this->steps[$position]);
            }
        }
        $this->steps = array_values($this->steps);
        $this->steps[] = ['name' => $name, 'startedAt' => $startedAt, 'endedAt' => null, 'current' => null, 'total' => null];
    }

    private function endStep(\DateTimeImmutable $now): void
    {
        $last = array_key_last($this->steps);
        if (null !== $last && null === $this->steps[$last]['endedAt']) {
            $this->steps[$last]['endedAt'] = $now;
        }
    }

    /**
     * The steps as CommandRun::steps() reads them, the wait on the current one.
     */
    private function stepsJson(): ?string
    {
        if ([] === $this->steps) {
            return null;
        }
        $steps = [];
        foreach ($this->steps as $step) {
            $steps[] = [
                'name' => $step['name'],
                'startedAt' => $step['startedAt']->format(\DATE_ATOM),
                'endedAt' => $step['endedAt']?->format(\DATE_ATOM),
                'current' => $step['current'],
                'total' => $step['total'],
                'waiting' => null === $step['endedAt'] ? $this->waiting : null,
            ];
        }

        return json_encode($steps, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    private function message(): ?string
    {
        $parts = $this->warningMessages;
        if ($this->warnings > count($this->warningMessages)) {
            $parts[] = sprintf('... and %d more warnings', $this->warnings - count($this->warningMessages));
        }
        if (null !== $this->stoppedAs) {
            array_unshift($parts, $this->stopReason ?? 'Asked to stop');
        } elseif (null !== $this->error) {
            array_unshift($parts, $this->error);
        }

        return [] === $parts ? null : self::shorten(implode("\n", $parts), self::MAX_MESSAGE_LENGTH);
    }

    /**
     * Deletes the runs older than RETENTION, and of the job all but the latest MAX_RUNS_PER_JOB - 1 runs, making room for the new one.
     */
    private function prune(string $job, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'DELETE FROM command_run WHERE started_at < ?',
            [$now->modify(self::RETENTION)],
            [Types::DATETIME_IMMUTABLE],
        );
        // The id of the oldest run to keep; MySQL does not allow LIMIT in a subquery of DELETE
        $oldestKept = $this->connection->fetchOne(
            'SELECT id FROM command_run WHERE job = ? ORDER BY id DESC LIMIT 1 OFFSET ' . (self::MAX_RUNS_PER_JOB - 2),
            [$job],
        );
        if (false !== $oldestKept) {
            $this->connection->executeStatement('DELETE FROM command_run WHERE job = ? AND id < ?', [$job, (int) $oldestKept]);
        }
    }

    private static function shorten(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }

    private function write(callable $statement): void
    {
        try {
            try {
                $statement();
            } catch (\Doctrine\DBAL\Exception\ConnectionLost|\Doctrine\DBAL\Exception\ConnectionException) {
                $this->connection->close();
                $statement();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Recording the command run failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
