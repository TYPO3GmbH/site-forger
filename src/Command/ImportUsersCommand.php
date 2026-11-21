<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

namespace App\Command;

use App\CommandRun\CommandRunRecorder;
use App\Elastic\IndexSetup;
use App\Http\ApiRequests;
use App\Import\ForgeUserSync;
use App\Import\GerritAccountLookup;
use App\Import\ImportRun;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'app:import:users', description: 'Fills the users from Forge: some that are due per run, or the ones given')]
final class ImportUsersCommand extends ImportJobCommand
{
    public const string LOCK = 'users';
    public const string STEP_TAKE_OVER = 'Take the users of the issues over';

    public function __construct(
        private readonly ForgeUserSync $userSync,
        private readonly GerritAccountLookup $gerritLookup,
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
            ->addArgument('users', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The Forge numbers of the users to load again')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the given users from the index')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Users to ask Forge for at most', (string) ForgeUserSync::PER_RUN);
    }

    protected function lockName(): string
    {
        return self::LOCK;
    }

    protected function import(InputInterface $input, ImportRun $run): void
    {
        $forgeIds = self::numbers($input, 'users');
        if ($input->getOption('remove')) {
            if ([] === $forgeIds) {
                throw new \InvalidArgumentException('--remove needs the Forge numbers of the users');
            }
            $run->writeln(sprintf('Removed %d users', $this->userSync->remove($forgeIds)));

            return;
        }
        if ([] !== $forgeIds) {
            $this->report($run, $this->userSync->update($forgeIds, new \DateTimeImmutable()));

            return;
        }

        $this->recorder->progress(self::STEP_TAKE_OVER);
        $taken = $this->userSync->takeOverFromIssues();
        $this->recorder->progress(self::STEP_TAKE_OVER, $taken, $taken);
        $run->writeln(sprintf('Took %d users over from the issues', $taken));

        $limit = max(0, (int) $input->getOption('limit'));
        $this->recorder->progress(ForgeUserSync::STEP, 0, null);
        $this->report($run, $this->userSync->fetchDue($limit, new \DateTimeImmutable()));

        $this->recorder->progress(GerritAccountLookup::STEP, 0, null);
        $lookedUp = $this->gerritLookup->lookUpDue($limit, new \DateTimeImmutable());
        $run->writeln(sprintf(
            'Asked Gerrit for %d users without an email, %d of them have an account',
            $lookedUp['asked'],
            $lookedUp['found'],
        ));
    }

    /**
     * @param array{updated: int, gone: int, stopped: string|null} $result
     */
    private function report(ImportRun $run, array $result): void
    {
        $run->writeln(sprintf('Loaded %d users from Forge, %d of them unknown to Forge', $result['updated'] + $result['gone'], $result['gone']));
        if (null !== $result['stopped']) {
            $this->recorder->note(sprintf('Stopped early (%s), the next run goes on.', $result['stopped']));
        }
    }
}
