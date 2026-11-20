<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\DTO;

/**
 * A step of a run as the command runs page shows it, see App\Entity\CommandRun::steps(): a step it went through,
 * the one it is in, where it stopped, or one of the steps declared for its job it did not get to (yet).
 */
final readonly class CommandRunStep
{
    public const string DONE = 'done';
    public const string CURRENT = 'current';
    /** The step the run ended in without success: failed, stopped, aborted */
    public const string STOPPED = 'stopped';
    /** Declared for the job, the running run did not get to it yet */
    public const string UPCOMING = 'upcoming';
    /** Declared for the job, the run ended without it, e.g. the check of what Gerrit did not list after a listing that ended early */
    public const string SKIPPED = 'skipped';

    /**
     * @param ?int    $seconds how long the step took, or has been running up to the last heartbeat; null for a step it did not get to
     * @param ?string $waiting what the current step waits for, a rate limit for example
     */
    public function __construct(
        public string $name,
        public string $state,
        public ?int $current = null,
        public ?int $total = null,
        public ?int $seconds = null,
        public ?string $waiting = null,
    ) {
    }

    /**
     * 0 to 100, null without a total.
     */
    public function percent(): ?int
    {
        if (null === $this->current || null === $this->total || $this->total <= 0) {
            return null;
        }

        return (int) min(100, floor($this->current * 100 / $this->total));
    }
}
