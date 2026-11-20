<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Scheduler;

use App\Command\ImportJobCommand;
use App\Command\ImportReviewCommand;
use App\Import\GerritImport;
use App\Scheduler\JobLocks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;

/**
 * The locks of the schedule and of the imports, as the app configures them: in var/lock of the installation, so two
 * installations on one server (stage and live) do not lock each other.
 */
final class LocksTest extends KernelTestCase
{
    public function testTheLocksAreInVarLockOfTheInstallation(): void
    {
        self::bootKernel();
        $lockDirectory = self::$kernel->getProjectDir() . '/var/lock';
        $lock = self::getContainer()->get(LockFactory::class)->createLock('forger-locks-test');

        self::assertTrue($lock->acquire());
        $files = glob($lockDirectory . '/*forger-locks-test*') ?: [];
        $lock->release();

        self::assertCount(1, $files);
    }

    public function testTheQueueChecksTheLocksTheImportsTake(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        // The lock factory of the imports, set for their LockableTrait
        $importLocks = new \ReflectionProperty(ImportJobCommand::class, 'lockFactory')->getValue($container->get(ImportReviewCommand::class));
        self::assertSame($container->get(LockFactory::class), $importLocks);

        $held = $importLocks->createLock(GerritImport::LOCK);
        self::assertTrue($held->acquire());
        try {
            self::assertFalse($container->get(JobLocks::class)->isFree(GerritImport::LOCK));
        } finally {
            $held->release();
        }
        self::assertTrue($container->get(JobLocks::class)->isFree(GerritImport::LOCK));
    }
}
