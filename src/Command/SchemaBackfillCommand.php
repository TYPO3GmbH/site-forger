<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\CommandRun\CommandRunRecorder;
use App\CommandRun\JobStopped;
use App\Import\Backfill\SchemaBackfill;
use App\Import\ImportRun;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Lifts the stored issues and changes without Forge or Gerrit: to a newer version of ImportSchema, and their events
 * of the activity stream to the current StreamSchema, see SchemaBackfill. The job "Lift stored documents and write
 * the activity stream" runs it every five minutes, see JobSchedule. A run takes all documents and does nothing when
 * none are left.
 */
#[AsCommand(
    name: 'app:import:backfill',
    description: 'Lifts the stored documents to the current versions and writes their activity',
)]
final class SchemaBackfillCommand extends Command
{
    public const string LOCK = 'schema_backfill';

    public function __construct(
        private readonly SchemaBackfill $backfill,
        private readonly CommandRunRecorder $recorder,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->lockFactory->createLock(self::LOCK);
        if (!$lock->acquire()) {
            $output->writeln('Another backfill runs.');
            $this->recorder->locked();

            return Command::SUCCESS;
        }
        try {
            $lifted = $this->backfill->run(new ImportRun($output, $this->recorder, $lock->refresh(...)));
            $output->writeln(0 === $lifted ? 'No documents to lift.' : sprintf('Lifted %d documents.', $lifted));
        } catch (JobStopped $stopped) {
            $output->writeln('Stopped: ' . $stopped->getMessage());

            return Command::FAILURE;
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
