<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Elastic\IndexSetup;
use App\Http\ApiRequests;
use App\Http\RetryPolicy;
use App\Import\ImportRun;
use App\Service\CommandRunRecorder;
use App\Service\JobStopped;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs an import with its lock, the retries of a job and the same handling of failures.
 */
abstract class ImportJobCommand extends Command implements LoggerAwareInterface
{
    use LockableTrait;
    use LoggerAwareTrait;

    public function __construct(
        private readonly IndexSetup $indexSetup,
        protected readonly CommandRunRecorder $recorder,
        private readonly ApiRequests $apiRequests,
        LockFactory $lockFactory,
    ) {
        parent::__construct();
        // The locks of the app (config/packages/lock.yaml). The semaphores of the server that LockableTrait takes
        // otherwise are shared by all installations on the server, so stage would block live.
        $this->lockFactory = $lockFactory;
    }

    abstract protected function lockName(): string;

    abstract protected function import(InputInterface $input, ImportRun $run): void;

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->apiRequests->retryWith(RetryPolicy::job(), $this->refreshLock(...));
        // Elasticsearch would create a missing index with a dynamic mapping
        foreach ($this->indexSetup->ensureAll() as $name => $result) {
            if (IndexSetup::UNCHANGED !== $result['action']) {
                new SymfonyStyle($input, $output)->note(sprintf('Elasticsearch index "%s" %s (%d documents)', $name, $result['action'], $result['documents']));
            }
        }
        if (!$this->lock($this->lockName())) {
            $output->writeln(sprintf('Locked by another %s import', $this->lockName()));
            $this->recorder->locked();

            return Command::SUCCESS;
        }
        try {
            $this->import($input, new ImportRun($output, $this->recorder, $this->refreshLock(...)));
        } catch (JobStopped $stopped) {
            $output->writeln('Stopped: ' . $stopped->getMessage());

            return Command::FAILURE;
        } catch (\Exception $exception) {
            $this->logger?->error($exception->getMessage(), ['exception' => $exception]);
            $this->recorder->fail($exception::class . ': ' . $exception->getMessage(), $exception);
            $output->writeln('Failed: ' . $exception->getMessage());

            return Command::FAILURE;
        } finally {
            $this->release();
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    protected static function numbers(InputInterface $input, string $argument): array
    {
        return array_values(array_map(intval(...), (array) $input->getArgument($argument)));
    }

    private function refreshLock(): void
    {
        $this->lock?->refresh();
    }
}
