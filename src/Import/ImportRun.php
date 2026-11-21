<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\CommandRun\CommandRunRecorder;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What an import needs from the command that runs it.
 */
final readonly class ImportRun
{
    private const string BAR_FORMAT = '%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%';

    /**
     * @param \Closure(): void $refreshLock
     */
    public function __construct(
        private OutputInterface $output,
        private CommandRunRecorder $recorder,
        private \Closure $refreshLock,
    ) {
    }

    public function writeln(string $message): void
    {
        $this->output->writeln($message);
    }

    public function progressBar(int $max = 0): ProgressBar
    {
        $output = $this->output instanceof ConsoleOutputInterface ? $this->output->section() : $this->output;
        $bar = new ProgressBar($output, $max);
        $bar->setFormat(self::BAR_FORMAT);

        return $bar;
    }

    /**
     * @param string|null $step shows the progress on the command runs page too
     */
    public function advance(ProgressBar $bar, ?string $step): void
    {
        $bar->advance();
        if (null !== $step) {
            $this->recorder->progress($step, $bar->getProgress(), $bar->getMaxSteps() > 0 ? $bar->getMaxSteps() : null);
        }
    }

    public function finish(ProgressBar $bar): void
    {
        $bar->finish();
        $this->output->writeln('');
    }

    public function refreshLock(): void
    {
        ($this->refreshLock)();
    }
}
