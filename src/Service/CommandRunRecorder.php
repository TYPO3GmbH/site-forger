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
    private const int MAX_WARNING_MESSAGES = 5;
    private const int MAX_WARNING_LENGTH = 300;
    private const int MAX_ERROR_LENGTH = 1000;
    private const int MAX_MESSAGE_LENGTH = 2000;
    private const int HEARTBEAT_INTERVAL = 15;

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
    private int $lastHeartbeat = 0;

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
        $this->lastHeartbeat = time();
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
     * What the command is doing and how far it got, shown while it runs. Updates the run now and then.
     */
    public function progress(?string $phase, ?int $current = null, ?int $total = null): void
    {
        $phaseChanged = $phase !== $this->phase;
        $this->phase = null === $phase ? null : self::shorten($phase, 255);
        $this->progressCurrent = $current;
        $this->progressTotal = $total;
        // A new phase shows right away
        $this->heartbeat($phaseChanged);
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
            0 !== $exitCode || null !== $this->error => CommandRunStatusEnum::FAILED,
            $this->locked => CommandRunStatusEnum::LOCKED,
            $this->warnings > 0 => CommandRunStatusEnum::WARNING,
            default => CommandRunStatusEnum::SUCCESS,
        };
        $now = new \DateTimeImmutable();
        $this->write(fn (): int => $this->connection->update('command_run', [
            'status' => $status->value,
            'heartbeat_at' => $now,
            'finished_at' => $now,
            'exit_code' => $exitCode,
            'phase' => $this->phase,
            'progress_current' => $this->progressCurrent,
            'progress_total' => $this->progressTotal,
            'documents' => $this->documents,
            'warnings' => $this->warnings,
            'message' => $this->message(),
        ], ['id' => $this->runId], [
            'heartbeat_at' => Types::DATETIME_IMMUTABLE,
            'finished_at' => Types::DATETIME_IMMUTABLE,
        ]));
        $this->runId = null;
    }

    private function heartbeat(bool $now = false): void
    {
        if (null === $this->runId || (!$now && time() - $this->lastHeartbeat < self::HEARTBEAT_INTERVAL)) {
            return;
        }
        $this->lastHeartbeat = time();
        $this->write(fn (): int => $this->connection->update('command_run', [
            'heartbeat_at' => new \DateTimeImmutable(),
            'phase' => $this->phase,
            'progress_current' => $this->progressCurrent,
            'progress_total' => $this->progressTotal,
            'documents' => $this->documents,
            'warnings' => $this->warnings,
            'message' => $this->message(),
        ], ['id' => $this->runId], [
            'heartbeat_at' => Types::DATETIME_IMMUTABLE,
        ]));
    }

    private function message(): ?string
    {
        $parts = $this->warningMessages;
        if ($this->warnings > count($this->warningMessages)) {
            $parts[] = sprintf('... and %d more warnings', $this->warnings - count($this->warningMessages));
        }
        if (null !== $this->error) {
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
