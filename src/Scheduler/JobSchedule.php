<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Scheduler;

use App\Command\ImportUsersCommand;
use App\Import\ForgeImport;
use App\Import\ForgeUserSync;
use App\Import\GerritImport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * When the jobs run: the imports and the backports, in the app instead of the crontab of the server.
 *
 * Cron starts the worker of the schedule once a minute, for less than a minute:
 *
 *     * * * * *  bin/console messenger:consume scheduler_jobs --time-limit=55
 *
 * Once a minute the worker also starts the jobs queued on the command runs page, see StartQueuedJobsHandler.
 *
 * A due job is started as a process of its own, see StartJobHandler: the worker is done with it at once, and a
 * long run keeps no other job waiting. The locks of the imports keep two runs of one kind apart,
 * the command runs page shows every run.
 *
 * The schedule keeps its state between the workers (stateful): a job due between two of them, or while the cron
 * did not run, runs once when the next worker starts (processOnlyLastMissedRun), not once for every missed time.
 * The state lies in var/scheduler, which the deployments keep (var/cache they delete). The lock keeps two workers
 * from starting the same run, should one still run when cron starts the next.
 */
#[AsSchedule(self::NAME)]
final readonly class JobSchedule implements ScheduleProviderInterface
{
    public const string NAME = 'jobs';
    /**
     * The time zone of the weekly jobs, and of the times on the pages (config/packages/twig.yaml): the server runs
     * in UTC.
     */
    public const string TIMEZONE = 'Europe/Berlin';

    /**
     * The jobs: what they do in a few words, for the command runs page, when they run, every so many minutes
     * from midnight or once a week on a weekday at a time (server time), the console command with its
     * arguments, the lock it takes, if any: a queued job waits while it is held, see StartQueuedJobsHandler, and
     * the steps it reports, if any, in their order: the command runs page shows those still to come as well.
     *
     * @var array<string, array{label: string, every?: string, weekly?: array{string, string}, command: list<string>, lock?: string, steps?: list<string>}>
     */
    public const array JOBS = [
        'forgeDelta' => ['label' => 'Update Forge issues', 'every' => '5 minutes', 'command' => ['app:import:forge'], 'lock' => ForgeImport::LOCK, 'steps' => [ForgeImport::STEP_DELTA, ForgeImport::STEP_OUTDATED, ForgeImport::STEP_RECONCILE]],
        'reviewDelta' => ['label' => 'Update Gerrit changes', 'every' => '5 minutes', 'command' => ['app:import:review'], 'lock' => GerritImport::LOCK, 'steps' => [GerritImport::STEP_DELTA_LIST, GerritImport::STEP_DELTA_LOAD, GerritImport::STEP_OUTDATED]],
        'users' => ['label' => 'Update users', 'every' => '5 minutes', 'command' => ['app:import:users'], 'lock' => ImportUsersCommand::LOCK, 'steps' => [ImportUsersCommand::STEP_TAKE_OVER, ForgeUserSync::STEP]],
        'backports' => ['label' => 'Find missing backports', 'every' => '5 minutes', 'command' => ['app:backports']],
    ];

    public function __construct(
        #[Autowire(service: 'app.scheduler.state')]
        private CacheInterface $state,
        private LockFactory $lockFactory,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $schedule = (new Schedule())
            ->stateful($this->state)
            ->processOnlyLastMissedRun(true)
            ->lock($this->lockFactory->createLock('scheduler:' . self::NAME));
        foreach (self::JOBS as $name => $job) {
            $schedule->add(RecurringMessage::trigger(self::trigger($job), new StartJob($name, $job['label'], $job['command'], $job['lock'] ?? null)));
        }
        // The jobs an admin asked to run now, see App\Entity\QueuedJob
        $schedule->add(RecurringMessage::every('1 minute', new StartQueuedJobs(), from: new \DateTimeImmutable('today')));

        return $schedule;
    }

    /**
     * @param array{label: string, every?: string, weekly?: array{string, string}, command: list<string>, lock?: string, steps?: list<string>} $job
     */
    private static function trigger(array $job): TriggerInterface
    {
        return isset($job['weekly'])
            ? new WeeklyTrigger(...$job['weekly'])
            // From midnight: at :00, :05, :10, ..., whenever the worker starts
            : new PeriodicalTrigger(
                $job['every'] ?? throw new \LogicException('A job of the schedule needs "every" or "weekly"'),
                new \DateTimeImmutable('today'),
            );
    }
}
