<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\CommandRun\CommandRunRecorder;
use App\Elastic\IndexSetup;
use App\Http\ApiRequests;
use App\Import\DuplicateImport;
use App\Import\ForgeImport;
use App\Import\ForgeIssueImporter;
use App\Import\ForgeIssueList;
use App\Import\ImportRun;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'app:import:forge', description: 'Imports the issues of Forge: the changed ones, all of them, or the ones given')]
final class ImportForgeCommand extends ImportJobCommand
{
    public function __construct(
        private readonly ForgeImport $forgeImport,
        private readonly ForgeIssueImporter $issueImporter,
        private readonly DuplicateImport $duplicateImport,
        private readonly ForgeIssueList $forgeIssues,
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
            ->addArgument('issues', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The numbers of the issues to load again')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the given issues from the index')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Compare all issues instead of the changed ones')
            ->addOption('start-at', null, InputOption::VALUE_REQUIRED, 'With --full: the offset to start at', '0');
    }

    protected function lockName(): string
    {
        return ForgeImport::LOCK;
    }

    protected function import(InputInterface $input, ImportRun $run): void
    {
        $ids = self::numbers($input, 'issues');
        if ($input->getOption('remove')) {
            if ([] === $ids) {
                throw new \InvalidArgumentException('--remove needs the numbers of the issues');
            }
            $run->writeln(sprintf('Removed %d issues', $this->issueImporter->remove($ids)));

            return;
        }
        $this->checkAccess();
        if ([] !== $ids) {
            $result = $this->issueImporter->update($ids);
            $run->writeln(sprintf('Updated %d issues, removed %d that Forge does not list', $result['updated'], $result['removed']));
        } elseif ($input->getOption('full')) {
            $this->forgeImport->full($run, (int) $input->getOption('start-at'));
            $this->duplicateImport->run($run, new \DateTimeImmutable());
        } else {
            $this->forgeImport->delta($run);
            $this->duplicateImport->run($run, new \DateTimeImmutable());
        }
    }

    /**
     * Stops before the import when Forge refuses the issues to the token. Without this check the import fails on its
     * first list with a bare 403. The system page checks the token as well, see ForgeLogin.
     */
    private function checkAccess(): void
    {
        $problem = $this->forgeIssues->accessProblem();
        if (null !== $problem) {
            throw new \RuntimeException($problem);
        }
    }
}
