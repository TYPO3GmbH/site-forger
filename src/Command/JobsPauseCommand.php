<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Scheduler\JobControl;
use App\Scheduler\ScheduledJobs;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pauses the jobs of the schedule, one or all, and waits for those running to stop, e.g. before a deployment;
 * app:jobs:resume ends the pause. See App\Scheduler\JobControl.
 */
#[AsCommand(name: 'app:jobs:pause', description: 'Pauses the jobs of the schedule (all, or one) and waits for the running ones to stop')]
final class JobsPauseCommand extends Command
{
    public function __construct(
        private readonly JobControl $control,
        private readonly ScheduledJobs $scheduledJobs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('job', InputArgument::OPTIONAL, 'The job, like "forgeDelta" or "app:import --mode=forgeDelta"; all jobs without')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why, shown on the command runs page', 'Paused on the console')
            ->addOption('wait', null, InputOption::VALUE_REQUIRED, 'Seconds to wait at most for the running jobs to stop', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $job = JobsResumeCommand::job($this->scheduledJobs, $input->getArgument('job'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $stopping = $this->control->pause($job, 'console', (string) $input->getOption('reason'), new \DateTimeImmutable());
        $io->text(sprintf('Paused %s.', $job ?? 'all jobs'));
        if ([] === $stopping) {
            $io->success('No job was running.');

            return Command::SUCCESS;
        }
        $io->text(sprintf('Waiting for %d running %s to stop: %s', count($stopping), 1 === count($stopping) ? 'job' : 'jobs', implode(', ', array_map(static fn ($run): string => $run->getJob(), $stopping))));
        $left = $this->control->waitUntilStopped($stopping, (int) $input->getOption('wait'), static function (int $seconds): void {
            sleep($seconds);
        });
        if ([] !== $left) {
            // A run stops at its next heartbeat, one waiting for an answer of Forge or Gerrit takes longer
            $io->warning(sprintf('Still running after %d s, it stops at its next heartbeat: %s', (int) $input->getOption('wait'), implode(', ', array_map(static fn ($run): string => $run->getJob(), $left))));

            return Command::SUCCESS;
        }
        $io->success('The running jobs stopped, they start again once resumed.');

        return Command::SUCCESS;
    }
}
