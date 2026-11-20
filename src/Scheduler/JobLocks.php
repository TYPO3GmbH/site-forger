<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Scheduler;

use Symfony\Component\Lock\LockFactory;

/**
 * Whether the lock of a job is free, as the job takes it: the imports lock with the LockableTrait of the console and
 * the locks of the app (config/packages/lock.yaml), see App\Command\ImportCommand. A queued job waits while its lock
 * is held, see StartQueuedJobsHandler: started, it would end as "Skipped, locked".
 */
final readonly class JobLocks
{
    public function __construct(
        private LockFactory $factory,
    ) {
    }

    public function isFree(string $name): bool
    {
        $lock = $this->factory->createLock($name);
        if (!$lock->acquire()) {
            return false;
        }
        $lock->release();

        return true;
    }
}
