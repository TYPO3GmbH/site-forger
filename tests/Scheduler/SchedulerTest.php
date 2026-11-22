<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Scheduler;

use App\Entity\CommandRun;
use App\Entity\JobPause;
use App\Entity\QueuedJob;
use App\Enum\CommandRunStatusEnum;
use App\Import\ForgeImport;
use App\Import\GerritImport;
use App\Repository\CommandRunRepository;
use App\Repository\JobPauseRepository;
use App\Repository\QueuedJobRepository;
use App\Scheduler\JobControl;
use App\Scheduler\JobLocks;
use App\Scheduler\JobSchedule;
use App\Scheduler\ScheduledJobs;
use App\Scheduler\StartJob;
use App\Scheduler\StartJobHandler;
use App\Scheduler\StartQueuedJobs;
use App\Scheduler\StartQueuedJobsHandler;
use App\Scheduler\WeeklyTrigger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class SchedulerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function weeklyRuns(): iterable
    {
        // Friday: the Monday after
        yield 'later in the week' => ['2026-11-20 14:00', '2026-11-23 03:17'];
        // Monday before the time: the same day
        yield 'the same day, before' => ['2026-11-23 02:00', '2026-11-23 03:17'];
        // At or after the time: a week later
        yield 'the same day, at the time' => ['2026-11-23 03:17', '2026-11-30 03:17'];
        yield 'the same day, after' => ['2026-11-23 04:00', '2026-11-30 03:17'];
        // Sunday: the next day, not a week later
        yield 'Sunday' => ['2026-11-22 23:00', '2026-11-23 03:17'];
        // The time stays with the change to and from summer time (Europe/Berlin)
        yield 'summer time' => ['2026-03-24 12:00', '2026-03-30 03:17'];
        yield 'winter time' => ['2026-10-21 12:00', '2026-10-26 03:17'];
    }

    #[DataProvider('weeklyRuns')]
    public function testTheWeeklyTriggerKeepsWeekdayAndTime(string $run, string $expected): void
    {
        $zone = new \DateTimeZone('Europe/Berlin');
        $next = (new WeeklyTrigger('monday', '03:17'))->getNextRunDate(new \DateTimeImmutable($run, $zone));

        self::assertSame($expected, $next->format('Y-m-d H:i'));
        self::assertSame('Europe/Berlin', $next->getTimezone()->getName());
    }

    public function testTheWeeklyTriggerCountsInTheTimeZoneOfTheScheduleOnAServerInUtc(): void
    {
        // Friday 14:00 UTC: Monday 03:17 in Berlin is Monday 02:17 UTC in winter
        $next = (new WeeklyTrigger('monday', '03:17'))->getNextRunDate(new \DateTimeImmutable('2026-11-20 14:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-11-23 03:17', $next->format('Y-m-d H:i'));
        self::assertSame('2026-11-23 02:17', $next->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
    }

    public function testTheWeeklyTriggerSaysWhen(): void
    {
        self::assertSame('every Thursday at 03:17', (string) new WeeklyTrigger('thursday', '03:17'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wrongWeeklyRuns(): iterable
    {
        yield 'no weekday' => ['someday', '03:17'];
        yield 'German' => ['montag', '03:17'];
        yield 'no time' => ['monday', '3 am'];
        yield 'too late' => ['monday', '24:00'];
    }

    #[DataProvider('wrongWeeklyRuns')]
    public function testTheWeeklyTriggerWantsAWeekdayAndATime(string $weekday, string $time): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WeeklyTrigger($weekday, $time);
    }

    public function testTheScheduleHasEveryJobOnceWithItsCommand(): void
    {
        $schedule = (new JobSchedule(new ArrayAdapter(), new LockFactory(new InMemoryStore())))->getSchedule();

        $jobs = [];
        foreach ($schedule->getRecurringMessages() as $recurring) {
            foreach ($recurring->getMessages(new \Symfony\Component\Scheduler\Generator\MessageContext('jobs', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable())) as $message) {
                $jobs[$message instanceof StartJob ? $message->name : $message::class] = $message instanceof StartJob ? [(string) $recurring->getTrigger(), $message->command] : (string) $recurring->getTrigger();
            }
        }

        self::assertSame([
            'forgeDelta' => ['every 5 minutes', ['app:import:forge']],
            'reviewDelta' => ['every 5 minutes', ['app:import:review']],
            'users' => ['every 5 minutes', ['app:import:users']],
            'backfill' => ['every 5 minutes', ['app:import:backfill']],
            'backports' => ['every 5 minutes', ['app:backports']],
            StartQueuedJobs::class => 'every 1 minute',
        ], $jobs);
        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun());
        self::assertNotNull($schedule->getState());
        self::assertNotNull($schedule->getLock());
    }

    public function testTheRunsPageReadsTheJobsFromTheScheduleByTheNameOfTheirRuns(): void
    {
        $triggers = (new ScheduledJobs(new JobSchedule(new ArrayAdapter(), new LockFactory(new InMemoryStore()))))->triggers();

        self::assertSame(
            [
                'app:import:forge',
                'app:import:review',
                'app:import:users',
                'app:import:backfill',
                'app:backports',
            ],
            array_keys($triggers),
        );
        self::assertSame('every 5 minutes', (string) $triggers['app:import:review']);
    }

    public function testEveryJobSaysWhatItDoes(): void
    {
        self::assertSame([
            'app:import:forge' => 'Update Forge issues',
            'app:import:review' => 'Update Gerrit changes',
            'app:import:users' => 'Update users',
            'app:import:backfill' => 'Lift stored documents and write the activity stream',
            'app:backports' => 'Find missing backports',
        ], (new ScheduledJobs(new JobSchedule(new ArrayAdapter(), new LockFactory(new InMemoryStore()))))->labels());
    }

    public function testTheDeltasRunOnTheFiveMinutes(): void
    {
        $schedule = (new JobSchedule(new ArrayAdapter(), new LockFactory(new InMemoryStore())))->getSchedule();
        $trigger = array_values($schedule->getRecurringMessages())[0]->getTrigger();

        self::assertSame('14:45', $trigger->getNextRunDate(new \DateTimeImmutable('today 14:41:07'))?->format('H:i'));
        self::assertSame('14:50', $trigger->getNextRunDate(new \DateTimeImmutable('today 14:45:00'))?->format('H:i'));
    }

    public function testAJobStartsInTheBackgroundWithItsArgumentsAsTheyAre(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'job');
        // echo instead of PHP: it writes the command line to the log
        $commandLine = StartJobHandler::commandLine('echo', new StartJob('test', 'Test', ['app:import', '--mode=a b', '$HOME', '"; touch /tmp/x; "']), $log);
        $shell = proc_open($commandLine, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($shell);

        self::assertSame(0, proc_close($shell));
        // The shell ended, the job may still run: wait for its output
        for ($i = 0; $i < 50 && '' === (string) file_get_contents($log); ++$i) {
            usleep(20000);
        }
        self::assertSame('bin/console app:import --mode=a b $HOME "; touch /tmp/x; "' . "\n", file_get_contents($log));
        unlink($log);
    }

    public function testAJobKeepsNoFileOfTheWorkerOpenTheLockOfTheScheduleNeither(): void
    {
        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('Lists the open files with /proc, Linux only');
        }
        // The worker holds the lock of the schedule, a flock, as the scheduler does as long as the worker runs
        $lockFile = tempnam(sys_get_temp_dir(), 'lock');
        $lock = fopen($lockFile, 'r+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, \LOCK_EX | \LOCK_NB));
        $log = tempnam(sys_get_temp_dir(), 'job');

        // ls instead of PHP: the job lists the files it has open
        $shell = proc_open(
            StartJobHandler::commandLine('ls', new StartJob('test', 'Test', ['/proc/self/fd']), $log),
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($shell);
        self::assertSame(0, proc_close($shell));
        for ($i = 0; $i < 50 && !str_contains((string) file_get_contents($log), "\n2\n"); ++$i) {
            usleep(20000);
        }

        // 0, 1, 2 and the directory ls reads, not the lock of the worker
        $open = array_values(array_filter(explode("\n", (string) file_get_contents($log)), static fn (string $line): bool => ctype_digit($line)));
        self::assertSame(['0', '1', '2', '3'], $open);
        flock($lock, \LOCK_UN);
        fclose($lock);
        unlink($lockFile);
        unlink($log);
    }

    public function testAJobStartsWithItsCommand(): void
    {
        $job = new StartJob('reviewFull', 'Check all Gerrit changes', ['app:import:review', '--full'], 'gerrit');

        self::assertSame(['app:import:review', '--full'], array_slice(StartJobHandler::commandLine('php', $job, 'log'), 6));
    }

    public function testTheQueuedJobsStartOnceAndThoseNoLongerInTheScheduleAreDropped(): void
    {
        $projectDir = sys_get_temp_dir() . '/forger-queue-' . bin2hex(random_bytes(4));
        mkdir($projectDir);
        $backports = new QueuedJob('app:backports', 'dev-admin', new \DateTimeImmutable());
        $gone = new QueuedJob('app:import --mode=githubDelta', 'dev-admin', new \DateTimeImmutable());
        $taken = new QueuedJob('app:import:forge', 'dev-admin', new \DateTimeImmutable());
        $queue = $this->createMock(QueuedJobRepository::class);
        $queue->method('findQueued')->willReturn([$backports, $gone, $taken]);
        // Another worker took forgeDelta already
        $queue->expects(self::exactly(3))->method('claim')->willReturnCallback(static fn (QueuedJob $job): bool => $job !== $taken);
        $handler = $this->queueHandler($queue, new StartJobHandler($projectDir, $queue, $this->noPauses(), new JobLocks(new LockFactory(new InMemoryStore())), new NullLogger()));

        // A minute without a planned run of these jobs
        $handler->start(new \DateTimeImmutable('today 14:52:10'));

        // Started as the schedule starts it: its log file is there
        self::assertSame(['backports.lastrun'], array_values(array_diff(scandir($projectDir . '/var/log/jobs') ?: [], ['.', '..'])));
        array_map(unlink(...), glob($projectDir . '/var/log/jobs/*') ?: []);
        rmdir($projectDir . '/var/log/jobs');
        rmdir($projectDir . '/var/log');
        rmdir($projectDir . '/var');
        rmdir($projectDir);
    }

    public function testAPlannedStartTakesTheJobFromTheQueue(): void
    {
        $projectDir = sys_get_temp_dir() . '/forger-queue-' . bin2hex(random_bytes(4));
        mkdir($projectDir);
        $queue = $this->createMock(QueuedJobRepository::class);
        // Queued shortly before its planned run: it starts once, not twice
        $queue->expects(self::once())->method('dequeue')->with('app:backports');

        (new StartJobHandler($projectDir, $queue, $this->noPauses(), new JobLocks(new LockFactory(new InMemoryStore())), new NullLogger()))(new StartJob('backports', 'Find missing backports', ['app:backports']));

        array_map(unlink(...), glob($projectDir . '/var/log/jobs/*') ?: []);
        rmdir($projectDir . '/var/log/jobs');
        rmdir($projectDir . '/var/log');
        rmdir($projectDir . '/var');
        rmdir($projectDir);
    }

    public function testAJobPlannedForThisMinuteStaysQueuedForItsPlannedStart(): void
    {
        $queue = $this->createMock(QueuedJobRepository::class);
        $queue->method('findQueued')->willReturn([new QueuedJob('app:import:forge', 'dev-admin', new \DateTimeImmutable())]);
        // The planned run at 15:00 starts it and takes it from the queue, see StartJobHandler
        $queue->expects(self::never())->method('claim');

        $this->queueHandler($queue, new StartJobHandler(sys_get_temp_dir(), $queue, $this->noPauses(), new JobLocks(new LockFactory(new InMemoryStore())), new NullLogger()))->start(new \DateTimeImmutable('today 15:00:04'));
    }

    public function testAQueuedJobWaitsWhileItsLockIsTaken(): void
    {
        $store = new InMemoryStore();
        $queue = $this->createMock(QueuedJobRepository::class);
        $queue->method('findQueued')->willReturn([new QueuedJob('app:import:review', 'dev-admin', new \DateTimeImmutable())]);
        $queue->expects(self::never())->method('claim');
        $handler = $this->queueHandler($queue, new StartJobHandler(sys_get_temp_dir(), $queue, $this->noPauses(), new JobLocks(new LockFactory(new InMemoryStore())), new NullLogger()), new JobLocks(new LockFactory($store)));

        // reviewDelta is planned for 15:05 itself: the queued one waits
        $handler->start(new \DateTimeImmutable('today 15:05:00'));
        // A full import started on the console still runs a minute later, it holds the lock of Gerrit
        $full = (new LockFactory($store))->createLock(GerritImport::LOCK);
        $full->acquire();
        $handler->start(new \DateTimeImmutable('today 15:06:00'));
        $full->release();
    }

    public function testAQueuedJobStartsOnceItsLockIsFree(): void
    {
        $queue = $this->createMock(QueuedJobRepository::class);
        $queue->method('findQueued')->willReturn([new QueuedJob('app:import:review', 'dev-admin', new \DateTimeImmutable())]);
        $queue->expects(self::once())->method('claim')->willReturn(false);

        $this->queueHandler($queue, new StartJobHandler(sys_get_temp_dir(), $queue, $this->noPauses(), new JobLocks(new LockFactory(new InMemoryStore())), new NullLogger()))->start(new \DateTimeImmutable('today 15:06:00'));
    }

    public function testTheLocksAreCheckedAsTheConsoleTakesThem(): void
    {
        $store = new InMemoryStore();
        $locks = new JobLocks(new LockFactory($store));
        $held = (new LockFactory($store))->createLock('gerrit');

        self::assertTrue($locks->isFree('gerrit'));
        $held->acquire();
        self::assertFalse($locks->isFree('gerrit'));
        self::assertTrue($locks->isFree('forge'));
        $held->release();
        // Checking does not keep the lock
        self::assertTrue($locks->isFree('gerrit'));
        self::assertTrue($held->acquire());
        $held->release();
    }

    public function testAnEmptyQueueAsksTheScheduleForNothing(): void
    {
        $queue = $this->createMock(QueuedJobRepository::class);
        $queue->method('findQueued')->willReturn([]);
        $queue->expects(self::never())->method('claim');

        $this->queueHandler($queue, new StartJobHandler(sys_get_temp_dir(), $queue, $this->noPauses(), new JobLocks(new LockFactory(new InMemoryStore())), new NullLogger()))(new StartQueuedJobs());
    }

    public function testAPausedJobIsQueuedInsteadOfStarted(): void
    {
        $queue = $this->createMock(QueuedJobRepository::class);
        $queue->expects(self::once())->method('queue')->with('app:import:forge', self::stringContains('paused'));
        $queue->expects(self::never())->method('dequeue');

        (new StartJobHandler('/nonexistent', $queue, $this->pausesOf(JobPause::ALL), new JobLocks(new LockFactory(new InMemoryStore())), new NullLogger()))(new StartJob('forgeDelta', 'Update Forge issues', ['app:import:forge'], ForgeImport::LOCK));
    }

    public function testAJobWhoseLockIsTakenIsQueuedInsteadOfStarted(): void
    {
        // A full import started on the console runs: the planned forgeDelta waits for it, instead of "Skipped, locked" every five minutes
        $store = new InMemoryStore();
        $full = (new LockFactory($store))->createLock(ForgeImport::LOCK);
        $full->acquire();
        $queue = $this->createMock(QueuedJobRepository::class);
        $queue->expects(self::once())->method('queue')->with('app:import:forge', self::stringContains('lock'));

        (new StartJobHandler('/nonexistent', $queue, $this->noPauses(), new JobLocks(new LockFactory($store)), new NullLogger()))(new StartJob('forgeDelta', 'Update Forge issues', ['app:import:forge'], ForgeImport::LOCK));
        $full->release();
    }

    public function testAQueuedPausedJobStaysQueued(): void
    {
        $queue = $this->createMock(QueuedJobRepository::class);
        $queue->method('findQueued')->willReturn([new QueuedJob('app:backports', 'dev-admin', new \DateTimeImmutable())]);
        $queue->expects(self::never())->method('claim');
        $pauses = $this->pausesOf('app:backports');

        $this->queueHandler($queue, new StartJobHandler(sys_get_temp_dir(), $queue, $pauses, new JobLocks(new LockFactory(new InMemoryStore())), new NullLogger()), null, $pauses)->start(new \DateTimeImmutable('today 14:52:10'));
    }

    public function testPausingStopsTheRunningJobsAndQueuesThemToStartAgain(): void
    {
        $run = new \ReflectionClass(CommandRun::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(CommandRun::class, 'id')->setValue($run, 7);
        new \ReflectionProperty(CommandRun::class, 'job')->setValue($run, 'app:import:review --full');
        $pauses = $this->createMock(JobPauseRepository::class);
        $pauses->expects(self::once())->method('pause')->with(JobPause::ALL, 'deployment', 'a deployment');
        $runs = $this->createMock(CommandRunRepository::class);
        $runs->method('findRunning')->willReturn([$run]);
        $runs->expects(self::once())->method('requestStop')->with(7, CommandRunStatusEnum::INTERRUPTED, 'Interrupted: a deployment')->willReturn(true);
        $queue = $this->createMock(QueuedJobRepository::class);
        $queue->expects(self::once())->method('queue')->with('app:import:review --full', 'interrupted, a deployment', self::anything());

        $stopping = $this->control($pauses, $queue, $runs)->pause(null, 'deployment', 'a deployment', new \DateTimeImmutable());

        self::assertSame([$run], $stopping);
    }

    public function testOnlyJobsOfTheScheduleArePaused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->control($this->createStub(JobPauseRepository::class), $this->createStub(QueuedJobRepository::class), $this->createStub(CommandRunRepository::class))->pause('app:truncate-type', 'dev-admin', 'test', new \DateTimeImmutable());
    }

    public function testAPauseOfAllJobsCountsForEveryJob(): void
    {
        $control = $this->control($this->createStub(JobPauseRepository::class), $this->createStub(QueuedJobRepository::class), $this->createStub(CommandRunRepository::class));
        $all = new JobPause(JobPause::ALL, 'deployment', 'a deployment', new \DateTimeImmutable());
        $own = new JobPause('app:backports', 'dev-admin', 'test', new \DateTimeImmutable());

        self::assertSame($all, $control->pauseOf('app:import:forge', [JobPause::ALL => $all]));
        self::assertSame($own, $control->pauseOf('app:backports', ['app:backports' => $own]));
        self::assertNull($control->pauseOf('app:import:forge', ['app:backports' => $own]));
    }

    public function testTheWaitEndsOnceTheRunsStopped(): void
    {
        $run = new \ReflectionClass(CommandRun::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(CommandRun::class, 'id')->setValue($run, 7);
        $runs = $this->createStub(CommandRunRepository::class);
        // Running for the first two looks, stopped then
        $runs->method('isStillRunning')->willReturnOnConsecutiveCalls(true, true, false);
        $slept = 0;

        $left = $this->control($this->createStub(JobPauseRepository::class), $this->createStub(QueuedJobRepository::class), $runs)->waitUntilStopped([$run], 300, static function (int $seconds) use (&$slept): void {
            $slept += $seconds;
        });

        self::assertSame([], $left);
        self::assertSame(4, $slept);
    }

    private function noPauses(): JobPauseRepository
    {
        $pauses = $this->createStub(JobPauseRepository::class);
        $pauses->method('findPauses')->willReturn([]);

        return $pauses;
    }

    private function pausesOf(string $job): JobPauseRepository
    {
        $pauses = $this->createStub(JobPauseRepository::class);
        $pauses->method('findPauses')->willReturn([$job => new JobPause($job, 'dev-admin', 'test', new \DateTimeImmutable())]);

        return $pauses;
    }

    private function control(JobPauseRepository $pauses, QueuedJobRepository $queue, CommandRunRepository $runs): JobControl
    {
        return new JobControl($pauses, $queue, $runs, new ScheduledJobs(new JobSchedule(new ArrayAdapter(), new LockFactory(new InMemoryStore()))));
    }

    private function queueHandler(QueuedJobRepository $queue, StartJobHandler $startJob, ?JobLocks $locks = null, ?JobPauseRepository $pauses = null): StartQueuedJobsHandler
    {
        $pauses ??= $this->noPauses();

        return new StartQueuedJobsHandler(
            $queue,
            new ScheduledJobs(new JobSchedule(new ArrayAdapter(), new LockFactory(new InMemoryStore()))),
            $startJob,
            $locks ?? new JobLocks(new LockFactory(new InMemoryStore())),
            $this->control($pauses, $queue, $this->createStub(CommandRunRepository::class)),
            $pauses,
            new NullLogger(),
        );
    }
}
