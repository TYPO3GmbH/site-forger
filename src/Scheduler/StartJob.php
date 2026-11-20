<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Scheduler;

/**
 * A job of the schedule is due, see JobSchedule and StartJobHandler.
 */
final readonly class StartJob
{
    /**
     * @param string       $name    the name of the job, of its log file too
     * @param string       $label   what the job does in a few words, like "Update Forge issues"
     * @param list<string> $command the console command with its arguments
     * @param string|null  $lock    the lock the job takes, see JobLocks
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $command,
        public ?string $lock = null,
    ) {
    }
}
