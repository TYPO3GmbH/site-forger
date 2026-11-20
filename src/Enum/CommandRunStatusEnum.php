<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

/**
 * The outcome of a run of a console command, see App\Entity\CommandRun.
 *
 * The value is stored in "command_run.status".
 */
enum CommandRunStatusEnum: string
{
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case WARNING = 'warning';
    case LOCKED = 'locked';
    case FAILED = 'failed';
    case ABORTED = 'aborted';
    /**
     * Stopped for a pause, a deployment for instance: the job starts again once resumed, see App\Scheduler\JobControl.
     */
    case INTERRUPTED = 'interrupted';
    /**
     * Stopped by an admin, not to start again.
     */
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::RUNNING => 'Running',
            self::SUCCESS => 'Success',
            self::WARNING => 'Success with warnings',
            self::LOCKED => 'Skipped, locked',
            self::FAILED => 'Failed',
            self::ABORTED => 'Aborted',
            self::INTERRUPTED => 'Interrupted',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * The key of the state for styling: "status-pill-<severity>".
     */
    public function severity(): string
    {
        return match ($this) {
            self::RUNNING => 'run-running',
            self::SUCCESS => 'run-success',
            self::WARNING => 'run-warning',
            self::LOCKED => 'run-locked',
            self::FAILED => 'run-failed',
            self::ABORTED => 'run-aborted',
            self::INTERRUPTED => 'run-interrupted',
            self::CANCELLED => 'run-cancelled',
        };
    }

    /**
     * The states that need a look. A stored "running" is included, it turns into "aborted" without heartbeats.
     *
     * @return list<self>
     */
    public static function problems(): array
    {
        return [self::FAILED, self::ABORTED, self::WARNING, self::RUNNING];
    }

    /**
     * Whether the run did its work, so the data it imports is up to date.
     */
    public function isSuccessful(): bool
    {
        return self::SUCCESS === $this || self::WARNING === $this;
    }

    /**
     * Whether the run ended on purpose without its work: skipped for a lock, interrupted or cancelled. No failure,
     * and no reason for the job to be overdue yet.
     */
    public function isStoppedOnPurpose(): bool
    {
        return in_array($this, [self::LOCKED, self::INTERRUPTED, self::CANCELLED], true);
    }
}
