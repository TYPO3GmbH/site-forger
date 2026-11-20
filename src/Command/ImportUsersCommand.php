<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Service\CommandRunRecorder;
use App\Service\ForgeUserSync;
use App\Service\JobStopped;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Keeps the index "user" complete for the issues: takes the authors and assignees it lacks over from the issues,
 * then fills some of them from their source. A job of its own, so its runs show on the command runs page.
 *
 * Its name says what it keeps, not where from: the source is Forge now, see ForgeUserSync, and may be another one
 * later, my.typo3.org for one, under the same command and job.
 *
 * It takes the lock of the Forge import: the two never ask Forge at the same time, the schedule queues this one
 * while an import runs.
 */
#[AsCommand(name: 'app:import:users', description: 'Takes the users of the issues over and fills them from their source, some per run')]
final class ImportUsersCommand extends Command implements LoggerAwareInterface
{
    use LockableTrait;
    use LoggerAwareTrait;

    public const string STEP_TAKE_OVER = 'Take the users of the issues over';

    public function __construct(
        private readonly ForgeUserSync $userSync,
        private readonly CommandRunRecorder $recorder,
        LockFactory $lockFactory,
    ) {
        parent::__construct();
        // The locks of the app, as ImportCommand takes them
        $this->lockFactory = $lockFactory;
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Users to ask the source for at most', (string) ForgeUserSync::PER_RUN);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock(ImportCommand::LOCK_FORGE)) {
            $output->writeln('Locked by a forge import');
            $this->recorder->locked();

            return Command::SUCCESS;
        }
        try {
            $this->recorder->progress(self::STEP_TAKE_OVER);
            $taken = $this->userSync->takeOverFromIssues();
            $this->recorder->progress(self::STEP_TAKE_OVER, $taken, $taken);
            $output->writeln(sprintf('Took %d users over from the issues', $taken));

            $this->recorder->progress(ForgeUserSync::STEP, 0, null);
            $result = $this->userSync->fetchDue(max(0, (int) $input->getOption('limit')), new \DateTimeImmutable());
            $output->writeln(sprintf('Loaded %d users from Forge, %d of them unknown to Forge', $result['updated'] + $result['gone'], $result['gone']));
            if (null !== $result['stopped']) {
                $this->recorder->note(sprintf('Stopped early (%s), the next run goes on.', $result['stopped']));
            }
        } catch (JobStopped $stopped) {
            $output->writeln('Stopped: ' . $stopped->getMessage());

            return Command::FAILURE;
        } catch (\Exception $exception) {
            $this->logger?->error($exception->getMessage(), ['exception' => $exception]);
            $this->recorder->fail($exception::class . ': ' . $exception->getMessage(), $exception);

            return Command::FAILURE;
        } finally {
            $this->release();
        }

        return Command::SUCCESS;
    }
}
