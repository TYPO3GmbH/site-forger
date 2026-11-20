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
use App\Import\GerritImport;
use App\Import\ImportRun;
use App\Service\CommandRunRecorder;
use App\Service\GerritChangeImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'app:import:review', description: 'Imports the changes of Gerrit: the updated ones, all of them, or the ones given')]
final class ImportReviewCommand extends ImportJobCommand
{
    public function __construct(
        private readonly GerritImport $gerritImport,
        private readonly GerritChangeImporter $changeImporter,
        IndexSetup $indexSetup,
        CommandRunRecorder $recorder,
        ApiRequests $apiRequests,
        LockFactory $lockFactory,
    ) {
        parent::__construct($indexSetup, $recorder, $apiRequests, $lockFactory);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('changes', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The numbers of the changes to load again')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the given changes from the index')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Compare all changes instead of the updated ones')
            ->addOption('start-at', null, InputOption::VALUE_REQUIRED, 'With --full: the offset to start at', '0');
    }

    protected function lockName(): string
    {
        return GerritImport::LOCK;
    }

    protected function import(InputInterface $input, ImportRun $run): void
    {
        $numbers = self::numbers($input, 'changes');
        if ($input->getOption('remove')) {
            if ([] === $numbers) {
                throw new \InvalidArgumentException('--remove needs the numbers of the changes');
            }
            $run->writeln(sprintf('Removed %d changes', $this->changeImporter->remove($numbers)));
        } elseif ([] !== $numbers) {
            $result = $this->changeImporter->update($numbers);
            $run->writeln(sprintf('Updated %d changes, removed %d that Gerrit does not show', $result['updated'], $result['removed']));
        } elseif ($input->getOption('full')) {
            $this->gerritImport->full($run, (int) $input->getOption('start-at'));
        } else {
            $this->gerritImport->delta($run);
        }
    }
}
