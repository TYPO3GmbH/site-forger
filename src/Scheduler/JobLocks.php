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
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;

/**
 * Whether the lock of a job is free, as the job takes it: the imports lock with the LockableTrait of the console,
 * which uses a semaphore or, without, a file in the temporary directory, not the lock of the app. A queued job
 * waits while its lock is held, see StartQueuedJobsHandler: started, it would end as "Skipped, locked".
 */
final readonly class JobLocks
{
    private LockFactory $factory;

    /**
     * @param LockFactory|null $factory for the tests, the store of the LockableTrait otherwise
     */
    public function __construct(?LockFactory $factory = null)
    {
        $this->factory = $factory ?? new LockFactory(SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore());
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
