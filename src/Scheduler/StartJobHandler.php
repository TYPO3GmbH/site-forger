<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Scheduler;

use App\Entity\JobPause;
use App\Repository\JobPauseRepository;
use App\Repository\QueuedJobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Starts a due job as a process of its own and does not wait for it: the worker of the schedule is free again
 * at once, see JobSchedule. The process outlives the worker (nohup, in the background of a shell that ends
 * right away). Its output goes to var/log/jobs/<name>.lastrun, as the cron jobs wrote it before; how it went
 * the command runs page shows.
 *
 * A paused job does not start: it is queued, to start once resumed, see JobControl. Nor does a job whose lock
 * another job holds, like a delta while its full import runs: queued, it starts once the lock is free, instead
 * of ending at once as "Skipped, locked", every five minutes for hours.
 *
 * A start also does what "Run now" asked for: the job leaves the queue. Otherwise a job queued shortly before its
 * planned run would start twice in the same minute, and the second run would only find the lock of the first.
 */
#[AsMessageHandler]
final readonly class StartJobHandler
{
    /**
     * The shell reads and writes nothing, the job writes to its log file.
     */
    private const array NO_STREAMS = [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private QueuedJobRepository $queue,
        private JobPauseRepository $pauses,
        private JobLocks $locks,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StartJob $job): void
    {
        $command = implode(' ', $job->command);
        $pauses = $this->pauses->findPauses();
        $pause = $pauses[JobPause::ALL] ?? $pauses[$command] ?? null;
        if (null !== $pause) {
            $this->queue->queue($command, sprintf('planned, %s', $pause->isAll() ? 'all jobs are paused' : 'the job is paused'), new \DateTimeImmutable(), $job->forced);
            $this->logger->info(sprintf('Queued the job "%s", it is paused: %s', $job->name, $pause->getReason()));

            return;
        }
        if (null !== $job->lock && !$this->locks->isFree($job->lock)) {
            $this->queue->queue($command, sprintf('planned, waits for the lock of %s', ucfirst($job->lock)), new \DateTimeImmutable(), $job->forced);
            $this->logger->info(sprintf('Queued the job "%s", its lock "%s" is taken', $job->name, $job->lock));

            return;
        }
        $logDirectory = $this->projectDir . '/var/log/jobs';
        if (!is_dir($logDirectory) && !mkdir($logDirectory, 0o775, true) && !is_dir($logDirectory)) {
            throw new \RuntimeException(sprintf('The log directory "%s" could not be created', $logDirectory));
        }
        // The shell puts the job in the background and ends, proc_close() waits for the shell only
        $shell = proc_open(self::commandLine(PHP_BINARY, $job, $logDirectory . '/' . $job->name . '.lastrun'), self::NO_STREAMS, $pipes, $this->projectDir);
        if (false === $shell || 0 !== proc_close($shell)) {
            throw new \RuntimeException(sprintf('The job "%s" could not be started', $job->name));
        }
        $this->queue->dequeue($command);
        $this->logger->info(sprintf('Started the job "%s": %s%s', $job->name, implode(' ', $job->command), $job->forced ? ' --force' : ''));
    }

    /**
     * The command line that starts the job in the background: the shell gets the log file as $0 and the
     * command as "$@", nothing of it goes through the shell unquoted.
     *
     * @return list<string>
     */
    public static function commandLine(string $php, StartJob $job, string $logFile): array
    {
        return ['sh', '-c', 'nohup "$@" > "$0" 2>&1 < /dev/null &', $logFile, $php, 'bin/console', ...$job->command, ...($job->forced ? ['--force'] : [])];
    }
}
