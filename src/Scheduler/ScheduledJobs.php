<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Scheduler;

use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

/**
 * The jobs of the schedule as the scheduler sees them, for the command runs page: read from the schedule itself,
 * like debug:scheduler does, by the job as its runs are recorded ("app:import:forge").
 */
final readonly class ScheduledJobs
{
    public function __construct(
        private JobSchedule $schedule,
    ) {
    }

    /**
     * The jobs in the order of the schedule: when each runs, and the message that starts it.
     *
     * @return array<string, array{trigger: TriggerInterface, start: StartJob}>
     */
    public function jobs(): array
    {
        $jobs = [];
        foreach ($this->schedule->getSchedule()->getRecurringMessages() as $recurring) {
            $context = new MessageContext(JobSchedule::NAME, $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable());
            foreach ($recurring->getMessages($context) as $message) {
                if ($message instanceof StartJob) {
                    $jobs[implode(' ', $message->command)] = ['trigger' => $recurring->getTrigger(), 'start' => $message];
                }
            }
        }

        return $jobs;
    }

    /**
     * What each job does in a few words, like "Update Forge issues", in the order of the schedule.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return array_map(static fn (array $job): string => $job['start']->label, $this->jobs());
    }

    /**
     * The steps each job reports, in their order, see JobSchedule::JOBS; a job without steps has none.
     *
     * @return array<string, list<string>>
     */
    public function steps(): array
    {
        $steps = [];
        foreach (JobSchedule::JOBS as $job) {
            $steps[implode(' ', $job['command'])] = $job['steps'] ?? [];
        }

        return $steps;
    }

    /**
     * When each job runs, in the order of the schedule.
     *
     * @return array<string, TriggerInterface>
     */
    public function triggers(): array
    {
        return array_map(static fn (array $job): TriggerInterface => $job['trigger'], $this->jobs());
    }
}
