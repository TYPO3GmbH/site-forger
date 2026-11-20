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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ends the pause of app:jobs:pause: the queue starts what waits, the interrupted jobs too, within a minute.
 */
#[AsCommand(name: 'app:jobs:resume', description: 'Resumes the jobs of the schedule (all, or one) paused with app:jobs:pause')]
final class JobsResumeCommand extends Command
{
    public function __construct(
        private readonly JobControl $control,
        private readonly ScheduledJobs $scheduledJobs,
    ) {
        parent::__construct();
    }

    /**
     * The job as its runs are recorded, from its name ("forgeDelta") or itself, null for all jobs.
     *
     * @throws \InvalidArgumentException for a job that is not in the schedule
     */
    public static function job(ScheduledJobs $scheduledJobs, mixed $given): ?string
    {
        if (null === $given || '' === $given) {
            return null;
        }
        foreach ($scheduledJobs->jobs() as $job => $scheduled) {
            if ($given === $job || $given === $scheduled['start']->name) {
                return $job;
            }
        }
        $names = array_map(static fn (array $scheduled): string => $scheduled['start']->name, $scheduledJobs->jobs());

        throw new \InvalidArgumentException(sprintf('"%s" is no job of the schedule, these are: %s', (string) $given, implode(', ', $names)));
    }

    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::OPTIONAL, 'The job, like "forgeDelta" or "app:import:forge"; all jobs without');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $job = self::job($this->scheduledJobs, $input->getArgument('job'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $io->success($this->control->resume($job)
            ? sprintf('Resumed %s, what waits in the queue starts within a minute.', $job ?? 'all jobs')
            : sprintf('%s %s not paused.', ucfirst($job ?? 'all jobs'), null === $job ? 'were' : 'was'));

        return Command::SUCCESS;
    }
}
