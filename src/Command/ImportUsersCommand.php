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
use App\Import\ForgeUserSync;
use App\Import\GerritAccountLookup;
use App\Import\GerritAccountRepair;
use App\Import\GerritNames;
use App\Import\ImportRun;
use App\Import\UserForms;
use App\People\PersonLinkCleanup;
use App\Repository\AccountRequestRepository;
use App\Repository\UserRepository;
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
    public const string STEP_REQUESTED = 'Ask for the accounts the admins requested';

    /**
     * The accounts an admin requested that one run asks their source for, see AccountRequestRepository. More than
     * the due ones: an admin waits for them. With a run each 5 minutes, a thousand accounts take less than an hour.
     */
    public const int REQUESTED_PER_RUN = 100;

    public function __construct(
        private readonly ForgeUserSync $userSync,
        private readonly GerritAccountLookup $gerritLookup,
        private readonly GerritNames $gerritNames,
        private readonly UserForms $userForms,
        private readonly GerritAccountRepair $gerritRepair,
        private readonly PersonLinkCleanup $linkCleanup,
        private readonly AccountRequestRepository $requests,
        private readonly UserRepository $users,
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

        $this->recorder->progress(UserForms::STEP);
        $lifted = $this->userForms->lift();
        $this->recorder->progress(UserForms::STEP, $lifted, $lifted);
        $run->writeln(sprintf('Wrote %d users in the current form', $lifted));

        $this->recorder->progress(GerritAccountRepair::STEP);
        $repaired = $this->gerritRepair->repair();
        $this->recorder->progress(GerritAccountRepair::STEP, $repaired, $repaired);
        $run->writeln(sprintf('Took an account on Gerrit of another username from %d users', $repaired));

        $this->recorder->progress(self::STEP_TAKE_OVER);
        $taken = $this->userSync->takeOverFromIssues();
        $this->recorder->progress(self::STEP_TAKE_OVER, $taken, $taken);
        $run->writeln(sprintf('Took %d users over from the issues', $taken));

        $this->recorder->progress(GerritNames::STEP);
        $named = $this->gerritNames->fill();
        $this->recorder->progress(GerritNames::STEP, $named, $named);
        $run->writeln(sprintf('Took the name of Gerrit of %d users over from the activity', $named));

        $this->askRequested($run);

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

        $this->recorder->progress(PersonLinkCleanup::STEP);
        $removed = $this->linkCleanup->removeJoined();
        $this->recorder->progress(PersonLinkCleanup::STEP, $removed, $removed);
        $run->writeln(sprintf('Dropped %d %s the sources joined', $removed, 1 === $removed ? 'link' : 'links'));
    }

    /**
     * Asks the sources for the accounts the admins requested, the oldest requests first: Forge for "forge:<number>",
     * Gerrit for "gerrit:<number>". A request goes once its source answered. When a source fails, its requests stay
     * for the next run.
     */
    private function askRequested(ImportRun $run): void
    {
        $accounts = $this->requests->oldest(self::REQUESTED_PER_RUN);
        $this->recorder->progress(self::STEP_REQUESTED, 0, count($accounts));
        if ([] === $accounts) {
            return;
        }
        $total = count($accounts);
        $forge = self::numbersOf($accounts, 'forge');
        if ([] !== $forge) {
            $result = $this->userSync->update(
                array_values($forge),
                new \DateTimeImmutable(),
                fn (int $done) => $this->recorder->progress(self::STEP_REQUESTED, $done, $total),
            );
            if (null === $result['stopped']) {
                $this->requests->remove(array_keys($forge));
            }
            $this->report($run, $result);
        }
        $answered = [];
        foreach (array_keys(self::numbersOf($accounts, 'gerrit')) as $account) {
            $user = $this->users->byKey($account);
            // An account that has its username by now needs no answer
            $ask = null !== $user && null === $user->username;
            if ($ask && null === $this->gerritLookup->lookUpNumber($user, new \DateTimeImmutable())) {
                break;
            }
            $answered[] = $account;
            $this->recorder->progress(self::STEP_REQUESTED, count($forge) + count($answered), $total);
        }
        $this->requests->remove($answered);
        if ([] !== $answered) {
            $run->writeln(sprintf('Asked Gerrit for %d accounts the admins requested', count($answered)));
        }
        $this->recorder->progress(self::STEP_REQUESTED, $total, $total);
    }

    /**
     * @param list<string> $accounts like "forge:<number>"
     *
     * @return array<string, int> the numbers of the accounts of the source, by account
     */
    private static function numbersOf(array $accounts, string $source): array
    {
        $numbers = [];
        foreach ($accounts as $account) {
            if (str_starts_with($account, $source . ':')) {
                $numbers[$account] = (int) substr($account, strlen($source) + 1);
            }
        }

        return $numbers;
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
