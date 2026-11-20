<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Elastic\ElasticUtility;
use App\Elastic\ImportSchema;
use App\Elastic\ImportWatermark;
use App\Elastic\IndexSetup;
use App\Elastic\IssueReconciliation;
use App\Elastic\ProjectChanges;
use App\Elastic\ProjectIssues;
use App\Service\CommandRunRecorder;
use App\Service\ForgeIssueImporter;
use App\Service\ForgeIssueList;
use App\Service\GerritChangeImporter;
use App\Service\JobStopped;
use Elastica\Result;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Redmine\Api\Issue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'app:import', description: 'Imports data from Redmine (forge.typo3.org) and Gerrit (review.typo3.org)')]
class ImportCommand extends Command implements LoggerAwareInterface
{
    use LockableTrait;
    use LoggerAwareTrait;
    /**
     * At most this many issues or changes per delta run. The rest follows in the next run (Forge),
     * or with the weekly reviewFull (Gerrit, which only lists the newest changes first).
     */
    /**
     * The locks of the imports: one run of each source at a time, see App\Scheduler\JobLocks.
     */
    public const string LOCK_FORGE = 'forge';
    public const string LOCK_GERRIT = 'gerrit';
    /**
     * The steps of the imports as the command runs page shows them, see App\Scheduler\JobSchedule::JOBS.
     */
    public const string STEP_FORGE_FULL = 'Compare all Forge issues and update the outdated ones';
    public const string STEP_FORGE_VANISHED = 'Remove issues gone from Forge';
    public const string STEP_FORGE_DELTA = 'Import the issues changed since the last run';
    public const string STEP_FORGE_LATEST = 'Import the latest issues, the index is empty';
    public const string STEP_FORGE_OUTDATED = 'Load issues of an older import again';
    public const string STEP_FORGE_RECONCILE = 'Compare the issues with Forge';
    public const string STEP_GERRIT_FULL_LIST = 'Compare all Gerrit changes with the index';
    public const string STEP_GERRIT_FULL_LOAD = 'Load the outdated changes from Gerrit';
    public const string STEP_GERRIT_VANISHED = 'Remove changes gone from Gerrit';
    public const string STEP_GERRIT_DELTA_LIST = 'Find the changes updated since the last run';
    public const string STEP_GERRIT_DELTA_LOAD = 'Load the updated changes from Gerrit';
    public const string STEP_GERRIT_LATEST = 'Load the latest changes, the index is empty';
    public const string STEP_GERRIT_OUTDATED = 'Load changes of an older import again';
    private const int DELTA_LIMIT_FORGE = 2000;
    private const int DELTA_LIMIT_GERRIT = 3000;
    /**
     * The window a Gerrit delta that has to catch up starts with, halved until its changes fit in a run, down to
     * the shortest one: then it takes those anyway.
     */
    private const int GERRIT_CATCH_UP_WINDOW = 86400;
    private const int GERRIT_CATCH_UP_MIN_WINDOW = 60;
    /**
     * Issues of an older import each Forge delta loads again, see ImportSchema: three requests every five minutes,
     * the whole project in a day.
     */
    private const int OUTDATED_LIMIT_FORGE = 300;
    /**
     * Counts at Forge per run where the issues differ, see reconcileForgeIssues(): one more each time the
     * ranges are halved, a dozen find one issue among all of them.
     */
    private const int RECONCILE_COUNTS = 15;
    /**
     * Changes of an older import each Gerrit delta loads again: twelve requests every five minutes, all changes
     * in a day.
     */
    private const int OUTDATED_LIMIT_GERRIT = 300;
    private const int PAGE_SIZE = 100;
    /**
     * Retries of a rate-limited request to Forge, see waitForForge().
     */
    private const int FORGE_RETRIES = 6;
    /**
     * Changes per request when loading the details of the changed ones.
     */
    private const int GERRIT_DETAIL_BATCH = 25;
    /**
     * Changes per page of the light listing without details: Gerrit sends 500 in about the time of 100.
     */
    private const int GERRIT_LIST_PAGE_SIZE = 500;
    /**
     * Retries of a Gerrit query that is rate-limited or fails, see waitForGerrit().
     */
    private const int GERRIT_RETRIES = 4;
    protected static array $importModes = ['forgeFull', 'forgeDelta', 'reviewFull', 'reviewDelta'];
    protected OutputInterface $output;

    public function __construct(
        private readonly ParameterBagInterface $parameters,
        private readonly IndexSetup $indexSetup,
        private readonly CommandRunRecorder $recorder,
        private readonly ImportWatermark $watermark,
        private readonly ProjectIssues $projectIssues,
        private readonly ForgeIssueImporter $issueImporter,
        private readonly GerritChangeImporter $changeImporter,
        private readonly ProjectChanges $projectChanges,
        private readonly ForgeIssueList $forgeIssues,
        LockFactory $lockFactory,
    ) {
        parent::__construct();
        // The locks of the app (config/packages/lock.yaml), not the semaphores of the server LockableTrait takes
        // otherwise: those are the same for every installation on the server, stage would block live
        $this->lockFactory = $lockFactory;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'mode',
                null,
                InputOption::VALUE_REQUIRED,
                'Import Mode: Allowed values are \'forgeFull\', \'forgeDelta\', \'reviewFull\', \'reviewDelta\''
            )
            ->addOption(
                'startAt',
                null,
                InputOption::VALUE_OPTIONAL,
                'at which line should the script start',
                0
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->output = $output;
        $this->issueImporter->onRateLimit($this->waitForForge(...), self::FORGE_RETRIES);
        $this->changeImporter->onRetry($this->waitForGerrit(...), self::GERRIT_RETRIES);
        $mode = $input->getOption('mode');
        $startAt = (int) $input->getOption('startAt');
        if (!in_array($mode, self::$importModes, true)) {
            $io->error('Please select one of the modes: "' . implode('", "', self::$importModes) . '"');

            return Command::INVALID;
        }

        // Health check before writing: a missing index would be created by Elasticsearch with a dynamic mapping
        foreach ($this->indexSetup->ensureAll() as $name => $result) {
            if (IndexSetup::UNCHANGED !== $result['action']) {
                $io->note(sprintf('Elasticsearch index "%s" %s (%d documents)', $name, $result['action'], $result['documents']));
            }
        }

        try {
            switch ($mode) {
                case 'forgeFull':
                    if ($this->lock(self::LOCK_FORGE)) {
                        try {
                            $this->removeIssuesOfOtherProjects();
                            $this->importFromForge($startAt);
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another forge import');
                        $this->recorder->locked();

                        return Command::SUCCESS;
                    }
                    break;
                case 'forgeDelta':
                    if ($this->lock(self::LOCK_FORGE)) {
                        try {
                            $this->removeIssuesOfOtherProjects();
                            $upToDate = $this->importForgeDelta();
                            $this->importOutdatedForgeIssues();
                            // While the delta catches up the numbers differ anyway
                            if ($upToDate) {
                                $this->reconcileForgeIssues();
                            }
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another forge import');
                        $this->recorder->locked();

                        return Command::SUCCESS;
                    }

                    break;
                case 'reviewDelta':
                    if ($this->lock(self::LOCK_GERRIT)) {
                        try {
                            $this->importReviewDelta($output);
                            $this->importOutdatedReviews();
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another gerrit import');
                        $this->recorder->locked();

                        return Command::SUCCESS;
                    }
                    break;
                case 'reviewFull':
                    if ($this->lock(self::LOCK_GERRIT)) {
                        try {
                            $this->importReviews($output, null, $startAt);
                        } finally {
                            $this->release();
                        }
                    } else {
                        $output->writeln('Locked by another gerrit import');
                        $this->recorder->locked();

                        return Command::SUCCESS;
                    }
                    break;
            }
        } catch (JobStopped $stopped) {
            // Asked to stop, by a pause or an admin: the recorder records it as such
            $output->writeln('Stopped: ' . $stopped->getMessage());
            $this->release();

            return Command::FAILURE;
        } catch (\Exception $exception) {
            // If any exception occurs, release the lock, else the process will run never again.
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            $this->recorder->fail($exception::class . ': ' . $exception->getMessage(), $exception);
            $output->writeln('Release lock because of exception: ' . $exception->getMessage());
            $this->release();

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Goes through every issue, newest first. Only the issues the index does not have in their current state
     * are imported (journals, user, write), also those an older import wrote (see ImportSchema): the full import
     * is a safety net for what the deltas missed and costs little more than listing the issues.
     *
     * After a complete listing the issues the index has but Forge did not list are checked one by one, see
     * removeVanishedIssues().
     */
    private function importFromForge(int $startOffset): void
    {
        $offset = max(0, $startOffset);
        $page = $this->getAllForgeIssuesPaginated(self::PAGE_SIZE, $offset);
        $totalCount = (int) ($page['total_count'] ?? 0);
        $progressBar = new ProgressBar($this->output, max(0, $totalCount - $offset));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        $skipped = 0;
        $listed = [];
        while ($offset < $totalCount) {
            if ($offset > max(0, $startOffset)) {
                $page = $this->getAllForgeIssuesPaginated(self::PAGE_SIZE, $offset);
            }
            // break a soon as the API returns no more issues
            if ([] === ($page['issues'] ?? [])) {
                break;
            }
            foreach ($page['issues'] as $doc) {
                $listed[(string) $doc['id']] = true;
            }
            $skipped += $this->importIssues($page['issues'], self::STEP_FORGE_FULL, $progressBar, true);
            $offset += self::PAGE_SIZE;
        }
        $progressBar->finish();
        $this->output->writeln('');
        $this->output->writeln(sprintf('%d issues up to date in the index, skipped', $skipped));

        if (0 === $startOffset && $offset >= $totalCount) {
            $this->removeVanishedIssues($listed);
        }
    }

    /**
     * Issues the index has but Forge did not list: deleted on Forge, private now, moved to another project, or only
     * missed, as the order of the list shifts while the import runs for hours. Forge is asked for them in batches:
     * those it lists are imported again, the others are removed.
     *
     * @param array<string, true> $listed the ids Forge listed
     */
    private function removeVanishedIssues(array $listed): void
    {
        $vanished = [];
        foreach ($this->projectIssues->indexedIds() as $id) {
            if (!isset($listed[$id])) {
                $vanished[] = (int) $id;
            }
        }
        $this->output->writeln(sprintf('%d issues in the index that Forge did not list, checking them', count($vanished)));
        $this->recorder->progress(self::STEP_FORGE_VANISHED, 0, count($vanished));
        $removed = $this->reloadOrRemove($vanished, self::STEP_FORGE_VANISHED, null);
        $this->output->writeln(sprintf('Removed %d issues that are gone from Forge', $removed));
    }

    /**
     * Loads issues of the index from Forge again, in batches: those Forge lists for the index are imported, if not
     * up to date, the others are removed, they are deleted, private or in another project now.
     *
     * @param list<int> $ids
     *
     * @return int the number of removed issues
     */
    private function reloadOrRemove(array $ids, string $step, ?ProgressBar $progressBar): int
    {
        $remove = [];
        foreach (array_chunk($ids, ForgeIssueList::PAGE_SIZE) as $chunk) {
            $issues = $this->forgeIssues->byIds($chunk);
            $this->importIssues($issues, $step, $progressBar, true);
            array_push($remove, ...array_diff($chunk, array_map(static fn (array $issue): int => (int) $issue['id'], $issues)));
            $this->refreshLock();
        }

        return $this->projectIssues->remove(array_values($remove));
    }

    /**
     * Imports the issues changed since the latest change in the index, oldest first: a run that ends early
     * (limit, error) leaves the index at a consistent point, and the next run goes on from there.
     *
     * @return bool whether it imported every issue changed since, so the index is up to date
     */
    private function importForgeDelta(): bool
    {
        $since = $this->watermark->since('issue');
        if (null === $since) {
            // An empty index needs forgeFull, until then the latest issues at least
            $this->recorder->note('The issue index is empty: this run imported the latest issues only. "Check all Forge issues" fills it.');
            $this->importIssues($this->getAllForgeIssuesPaginated(self::PAGE_SIZE, 0)['issues'] ?? [], self::STEP_FORGE_LATEST, null, false);

            return false;
        }

        $filter = ImportWatermark::redmineFilter($since);
        $first = $this->getAllForgeIssuesPaginated(self::PAGE_SIZE, 0, $filter);
        $total = (int) ($first['total_count'] ?? 0);
        $limit = min($total, self::DELTA_LIMIT_FORGE);
        $this->output->writeln(sprintf('%d issues changed since %s, importing %d', $total, $since->format('Y-m-d H:i:s'), $limit));
        if ($total > self::DELTA_LIMIT_FORGE) {
            $this->recorder->note(sprintf(
                'Catching up: %s issues changed since %s, more than a run imports. This run imports the oldest %s, the next runs go on from there.',
                number_format($total),
                $since->format('j M Y'),
                number_format(self::DELTA_LIMIT_FORGE),
            ));
        }

        $progressBar = new ProgressBar($this->output, $limit);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $page = $first;
        $unchanged = 0;
        for ($offset = 0; $offset < $limit; $offset += self::PAGE_SIZE) {
            if ($offset > 0) {
                $page = $this->getAllForgeIssuesPaginated(self::PAGE_SIZE, $offset, $filter);
            }
            if ([] === ($page['issues'] ?? [])) {
                break;
            }
            $unchanged += $this->importIssues(array_slice($page['issues'], 0, $limit - $offset), self::STEP_FORGE_DELTA, $progressBar, true);
        }
        $progressBar->finish();
        $this->output->writeln('');
        $this->output->writeln(sprintf('%d of them unchanged since the last import, skipped', $unchanged));

        return $total <= self::DELTA_LIMIT_FORGE;
    }

    /**
     * Compares the issues of the index with those of Forge by their numbers, see IssueReconciliation: one request
     * when they are alike, as they are mostly. Where they differ, the issues are listed in ranges of a page:
     * those missing or not up to date are imported, those Forge does not list any more are removed. Catches what
     * no list of changes shows: issues deleted on Forge or private now, and those the index never had. At most
     * RECONCILE_COUNTS counts per run, the next run goes on.
     */
    private function reconcileForgeIssues(): void
    {
        $forge = $this->forgeIssues->count();
        $index = $this->projectIssues->count();
        $highest = max($forge['highest'] ?? 0, $this->projectIssues->highestId());
        if ($forge['count'] === $index) {
            $this->output->writeln(sprintf('%d issues on Forge and in the index', $index));

            return;
        }
        $this->output->writeln(sprintf('%d issues on Forge, %d in the index, comparing them by their numbers', $forge['count'], $index));
        $ranges = IssueReconciliation::ranges(
            1,
            $highest,
            $forge['count'],
            $index,
            fn (int $from, int $to): int => $this->forgeIssues->count($from, $to)['count'],
            fn (int $from, int $to): int => $this->projectIssues->count($from, $to),
            self::RECONCILE_COUNTS,
        );
        $removed = 0;
        foreach ($ranges as $position => [$from, $to]) {
            $this->recorder->progress(self::STEP_FORGE_RECONCILE, $position + 1, count($ranges));
            $issues = $this->forgeIssues->range($from, $to);
            $this->importIssues($issues, self::STEP_FORGE_RECONCILE, null, true);
            $listed = array_map(static fn (array $issue): int => (int) $issue['id'], $issues);
            $removed += $this->projectIssues->remove(array_values(array_diff($this->projectIssues->idsBetween($from, $to), $listed)));
            $this->refreshLock();
        }
        $this->output->writeln(sprintf('Compared %d ranges of issues, removed %d issues gone from Forge', count($ranges), $removed));
    }

    /**
     * Loads a share of the issues an older version of the import wrote again, the ones changed last first: they
     * are the ones looked at. Each run takes the next share, until the index has none left, see ImportSchema.
     */
    private function importOutdatedForgeIssues(): void
    {
        $ids = $this->outdated('issue', self::OUTDATED_LIMIT_FORGE, 'issues');
        if ([] === $ids) {
            return;
        }

        $progressBar = new ProgressBar($this->output, count($ids));
        // An issue of an older import counts as changed: each of them is written again
        $removed = $this->reloadOrRemove($ids, self::STEP_FORGE_OUTDATED, $progressBar);
        $progressBar->finish();
        $this->output->writeln('');
        if ($removed > 0) {
            $this->output->writeln(sprintf('Removed %d issues that are gone from Forge', $removed));
        }
    }

    /**
     * The same for the changes: loaded again in batches, those Gerrit does not show any more are removed.
     */
    private function importOutdatedReviews(): void
    {
        $numbers = $this->outdated('review', self::OUTDATED_LIMIT_GERRIT, 'changes');
        if ([] === $numbers) {
            return;
        }
        $progressBar = new ProgressBar($this->output, count($numbers));
        $remove = [];
        foreach (array_chunk($numbers, self::GERRIT_DETAIL_BATCH) as $batch) {
            $query = GerritChangeImporter::PROJECT . ' (' . implode(' OR ', array_map(static fn (int $number): string => 'change:' . $number, $batch)) . ')';
            $documents = $this->reviewBlock($query, count($batch), $progressBar, self::STEP_GERRIT_OUTDATED);
            $this->changeImporter->writeAll($documents);
            array_push($remove, ...array_diff($batch, array_keys($documents)));
        }
        if ([] !== $remove) {
            $this->output->writeln(sprintf('Removed %d changes that Gerrit does not show any more', $this->projectChanges->remove(array_values($remove))));
        }
        $progressBar->finish();
        $this->output->writeln('');
    }

    /**
     * The next documents an older version of the import wrote, the ones changed last first, see ImportSchema.
     *
     * @param 'issue'|'review' $index
     *
     * @return list<int> their numbers
     */
    private function outdated(string $index, int $limit, string $name): array
    {
        $results = ElasticUtility::getIndexByName($index)->search([
            'query' => ImportSchema::outdated($index),
            'sort' => [['updated_on' => 'desc']],
            '_source' => false,
            'size' => $limit,
            'track_total_hits' => true,
        ]);
        $ids = array_map(static fn (Result $result): int => (int) $result->getId(), $results->getResults());
        if ([] !== $ids) {
            $total = $results->getTotalHits();
            $this->output->writeln(sprintf('%d %s of an older import, loading %d again', $total, $name, count($ids)));
            $this->recorder->note(sprintf('%s %s of an older import left, each run loads %s again.', number_format(max(0, $total - count($ids))), $name, number_format($limit)));
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @param bool                       $skipUnchanged skip the issues the index has with the same "updated_on"
     *
     * @return int the number of skipped issues
     */
    private function importIssues(array $issues, string $phase, ?ProgressBar $progressBar, bool $skipUnchanged): int
    {
        // Issues of other projects (subprojects) are not imported, and removed if the index has them from before
        $otherProjects = [];
        foreach ($issues as $position => $doc) {
            if (!$this->projectIssues->belongsToProject($doc)) {
                $otherProjects[] = $doc['id'];
                unset($issues[$position]);
                if (null !== $progressBar) {
                    $progressBar->advance();
                }
            }
        }
        if ([] !== $otherProjects && ($removed = $this->projectIssues->remove($otherProjects)) > 0) {
            $this->output->writeln(sprintf('Removed %d issues of other projects from the index', $removed));
        }

        $changed = null;
        if ($skipUnchanged) {
            $updated = [];
            foreach ($issues as $doc) {
                $updated[(string) $doc['id']] = $this->fixDateFormat((string) $doc['updated_on'], 'Redmine');
            }
            $changed = array_flip(ImportWatermark::changed($updated, $this->watermark->storedUpdates('issue', array_keys($updated))));
        }
        $skipped = 0;
        foreach ($issues as $doc) {
            if (null !== $changed && !isset($changed[(string) $doc['id']])) {
                ++$skipped;
                if (null !== $progressBar) {
                    $progressBar->advance();
                    $this->reportProgress($phase, $progressBar);
                }
                continue;
            }
            try {
                $this->issueImporter->import($doc);
                $this->refreshLock();
            } catch (JobStopped $stopped) {
                throw $stopped;
            } catch (\Exception $e) {
                // The import goes on with the next issue
                $this->recorder->skipped('issue', (string) ($doc['id'] ?? '?'), $e);
            } finally {
                // Stephan Großberndt asked for a sleep of between 50 - 100ms between requests to Forge on April 15th, 2024 in Slack channel #t3a-t3g-infra
                // Since we run into a lot of requests here, 50ms should be fine to not block the overall import process for too long
                usleep(50000);
            }
            if (null !== $progressBar) {
                $progressBar->advance();
                $this->reportProgress($phase, $progressBar);
            }
        }

        return $skipped;
    }

    /**
     * Imports the Gerrit changes updated since the latest change in the index, see importReviews().
     */
    private function importReviewDelta(OutputInterface $output): void
    {
        $since = $this->watermark->since('review');
        if (null === $since) {
            $this->recorder->note('The review index is empty: this run imported the latest changes only. "Check all Gerrit changes" fills it.');
            $progressBar = new ProgressBar($output->section());
            $this->changeImporter->writeAll($this->reviewBlock(GerritChangeImporter::PROJECT, self::PAGE_SIZE, $progressBar, self::STEP_GERRIT_LATEST));

            return;
        }
        $this->importReviews($output, $since);
    }

    /**
     * Imports the Gerrit changes in two steps: a light query lists the changes with their update time (all of
     * them, or those updated since), only those the index does not have in this state are loaded with their
     * revisions, files and messages; those an older import wrote count as not in this state, see ImportSchema.
     *
     * Gerrit lists the newest changes first. The delta writes the changes once all of them are loaded, so a run
     * that fails half way does not move the latest change in the index, and the next run tries again. The full
     * import writes them batch by batch, it handles far more changes than fit in memory.
     *
     * After a complete listing the full import checks the changes the index has but Gerrit did not list, see
     * removeVanishedChanges().
     */
    private function importReviews(OutputInterface $output, ?\DateTimeImmutable $since, int $startAt = 0): void
    {
        $delta = null !== $since;
        [$listStep, $loadStep] = $delta ? [self::STEP_GERRIT_DELTA_LIST, self::STEP_GERRIT_DELTA_LOAD] : [self::STEP_GERRIT_FULL_LIST, self::STEP_GERRIT_FULL_LOAD];
        $progressBar = new ProgressBar($output->section());
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        // Step 1: which changes are not up to date in the index, without their details
        $query = GerritChangeImporter::PROJECT . ($delta ? ' ' . ImportWatermark::gerritOperator($since) : '');
        // Gerrit does not say how many changes it lists: the full listing finds about as many as the index has
        $expected = $delta ? null : $this->projectChanges->count();
        [$listed, $numbers, $changed, $more] = $this->listReviews($query, $delta ? self::DELTA_LIMIT_GERRIT : null, $startAt, $listStep, $expected);
        if ($delta && $more) {
            // Gerrit lists the newest first: the newest of them would move the start of the next run past the older
            // ones. The oldest window from the start that fits in a run instead, the next run goes on after it.
            $until = ImportWatermark::catchUpWindow($since, self::GERRIT_CATCH_UP_WINDOW, self::GERRIT_CATCH_UP_MIN_WINDOW, function (\DateTimeImmutable $until) use ($query, $listStep, &$listed, &$numbers, &$changed): bool {
                [$listed, $numbers, $changed, $more] = $this->listReviews($query . ' ' . ImportWatermark::gerritBefore($until), self::DELTA_LIMIT_GERRIT, 0, $listStep, null);

                return !$more;
            });
            $this->recorder->note(sprintf(
                'Catching up: more than %s changes were updated since %s, more than a run imports. This run imports those until %s, the next runs go on from there.',
                number_format(self::DELTA_LIMIT_GERRIT),
                $since->format('j M Y H:i'),
                $until->format('j M Y H:i'),
            ));
        }
        $output->writeln(sprintf(
            '%d changes %s, %d of them not up to date in the index',
            $listed,
            $delta ? 'updated since ' . $since->format('Y-m-d H:i:s') : 'in Gerrit',
            count($changed),
        ));

        // Step 2: the details of the changes that are not up to date
        $reviews = [];
        $progressBar->setMaxSteps(count($changed));
        foreach (array_chunk($changed, self::GERRIT_DETAIL_BATCH) as $batch) {
            $batchQuery = implode(' OR ', array_map(static fn (string $number): string => 'change:' . $number, $batch));
            $block = $this->reviewBlock($batchQuery, count($batch), $progressBar, $loadStep);
            if ($delta) {
                $reviews += $block;
                continue;
            }
            $this->changeImporter->writeAll($block);
        }

        $this->changeImporter->writeAll($reviews);

        if (!$delta && 0 === $startAt && !$more) {
            $this->removeVanishedChanges($numbers, $progressBar);
        }
    }

    /**
     * Lists the changes of a query without their details, and which of them the index does not have in their
     * current state.
     *
     * @param int|null $limit    changes at most, all of them without
     * @param int|null $expected about as many as the listing finds, for its progress
     *
     * @return array{int, array<int, true>, list<string>, bool} how many were listed, their numbers, those not up to
     *                                                          date, and whether Gerrit has more
     */
    private function listReviews(string $query, ?int $limit, int $startAt, string $listStep, ?int $expected): array
    {
        $listed = 0;
        $numbers = [];
        $changed = [];
        $more = true;
        for ($start = max(0, $startAt); $more && (null === $limit || $start < $limit); $start += self::GERRIT_LIST_PAGE_SIZE) {
            $changes = $this->changeImporter->find($query, self::GERRIT_LIST_PAGE_SIZE, $start, []);
            $updated = [];
            foreach ($changes as $change) {
                $updated[(string) $change['_number']] = $this->fixDateFormat($change['updated'], 'Gerrit');
                $numbers[(int) $change['_number']] = true;
            }
            $listed += count($updated);
            array_push($changed, ...ImportWatermark::changed($updated, $this->watermark->storedUpdates('review', array_keys($updated))));
            $more = [] !== $changes && array_key_exists('_more_changes', array_last($changes));
            // Past the expected number the end is unknown: no total, the bar does not stand at 100 % while it lists on
            $this->recorder->progress($listStep, $listed, null !== $expected && $listed < $expected ? $expected : null);
            $this->refreshLock();
        }

        return [$listed, $numbers, $changed, $more];
    }

    /**
     * Changes the index has but Gerrit did not list: private now, deleted, or only missed, as the order of the
     * list shifts while the import runs. Gerrit is asked for them in batches: those it shows are imported again,
     * the others are removed.
     *
     * @param array<int, true> $listed the numbers Gerrit listed
     */
    private function removeVanishedChanges(array $listed, ProgressBar $progressBar): void
    {
        $vanished = [];
        foreach ($this->projectChanges->indexedNumbers() as $number) {
            if (!isset($listed[$number])) {
                $vanished[] = $number;
            }
        }
        $this->output->writeln(sprintf('%d changes in the index that Gerrit did not list, checking them', count($vanished)));

        $remove = [];
        foreach (array_chunk($vanished, self::GERRIT_DETAIL_BATCH) as $position => $batch) {
            $this->recorder->progress(self::STEP_GERRIT_VANISHED, $position * self::GERRIT_DETAIL_BATCH + count($batch), count($vanished));
            $query = GerritChangeImporter::PROJECT . ' (' . implode(' OR ', array_map(static fn (int $number): string => 'change:' . $number, $batch)) . ')';
            $shown = [];
            foreach ($this->changeImporter->find($query, count($batch), 0, []) as $change) {
                $shown[] = (int) $change['_number'];
            }
            if ([] !== $shown) {
                // Within the step of the check: the batches count, not the changes loaded again
                $this->changeImporter->writeAll($this->reviewBlock(implode(' OR ', array_map(static fn (int $number): string => 'change:' . $number, $shown)), count($shown), $progressBar, null));
            }
            array_push($remove, ...array_diff($batch, $shown));
            $this->refreshLock();
        }
        $removed = $this->projectChanges->remove(array_values($remove));
        $this->output->writeln(sprintf('Removed %d changes that Gerrit does not show any more', $removed));
    }

    /**
     * The documents of the changes a query finds, with the progress of the import in the step, if any.
     * Errors end the import: an empty result would silently skip the changes of this block.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reviewBlock(string $query, int $count, ProgressBar $progressBar, ?string $step): array
    {
        return $this->changeImporter->documents($this->changeImporter->find($query, $count), function () use ($progressBar, $step): void {
            $progressBar->advance();
            if (null !== $step) {
                $this->reportProgress($step, $progressBar);
            }
            $this->refreshLock();
        });
    }

    /**
     * Removes the issues of other projects the index has from before, see ProjectIssues.
     */
    private function removeIssuesOfOtherProjects(): void
    {
        $removed = $this->projectIssues->removeOtherProjects();
        if ($removed > 0) {
            $this->output->writeln(sprintf('Removed %d issues of other projects from the index', $removed));
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    private function fixDateFormat(string $dateTimeString, string $source): string
    {
        return match ($source) {
            'Redmine' => ForgeIssueImporter::indexDate($dateTimeString),
            'Gerrit' => GerritChangeImporter::gerritDate($dateTimeString),
            default => throw new \InvalidArgumentException('Value of $source has to be either "Redmine" or "Gerrit"'),
        };
    }

    /**
     * Calls Forge, waits and retries while it rate-limits (429), and fails on any other error status.
     *
     * The pause doubles from 10 seconds up to 5 minutes, about 10 minutes in all: enough for a full import
     * running unattended over night not to end at a rate limit. A delta waiting that long holds its lock,
     * the next delta is skipped meanwhile.
     *
     * The Redmine client returns the body of an error response (e.g. the HTML page of a 429) instead of
     * throwing, so the status of the last response decides.
     *
     * @throws \RuntimeException
     */
    /**
     * @template T
     *
     * @param \Closure(Issue): T $callback
     *
     * @return T
     */
    private function callRedmine(\Closure $callback): mixed
    {
        return $this->issueImporter->call('issue', $callback);
    }

    /**
     * Gives Forge some time to relax after a rate limit, longer with each attempt.
     */
    private function waitForForge(int $attempt): void
    {
        $sleepTime = min(10 * 2 ** ($attempt - 1), 300);
        $this->logger->warning(sprintf('[RECV] Failed to fetch data from Forge, response code 429, retrying in %d seconds, attempt #%d...', $sleepTime, $attempt));
        $this->recorder->waiting(sprintf('Forge rate limit, waiting %d s (attempt %d of %d)', $sleepTime, $attempt, self::FORGE_RETRIES));
        $this->recorder->sleep($sleepTime);
        $this->refreshLock();
    }

    /**
     * @param string|null $updatedOn a Redmine filter, see ImportWatermark::redmineFilter(); with a filter the oldest come first
     */
    private function getAllForgeIssuesPaginated(int $limit, int $offset, ?string $updatedOn = null): array
    {
        $parameters = [
            'limit' => $limit,
            'offset' => $offset,
            'project_id' => $this->parameters->get('redmine_project_id'),
            'status_id' => '*',
            'sort' => null === $updatedOn ? 'updated_on:desc' : 'updated_on',
            // For "duplicates" and "duplicated_by", see ForgeIssueImporter::document()
            'include' => 'relations',
        ];
        if (null !== $updatedOn) {
            $parameters['updated_on'] = $updatedOn;
        }

        return $this->callRedmine(static fn (Issue $issues): mixed => $issues->list($parameters));
    }

    /**
     * Gives Gerrit some time after a rate limit or an error, as long as it asks for or longer with each attempt.
     */
    private function waitForGerrit(int $status, int $seconds, int $attempt): void
    {
        $this->logger->warning(sprintf('Gerrit responded with %d, retrying in %d seconds (attempt %d)...', $status, $seconds, $attempt));
        $this->recorder->waiting(sprintf('Gerrit responded with %d, waiting %d s (attempt %d of %d)', $status, $seconds, $attempt, self::GERRIT_RETRIES));
        $this->recorder->sleep($seconds);
        $this->refreshLock();
    }

    /**
     * Shows the progress of the console on the command runs page too.
     */
    private function reportProgress(string $phase, ProgressBar $progressBar): void
    {
        $this->recorder->progress($phase, $progressBar->getProgress(), $progressBar->getMaxSteps() > 0 ? $progressBar->getMaxSteps() : null);
    }

    /**
     * Refreshes the lock to avoid a too-early expiration of its TTL.
     */
    private function refreshLock(): void
    {
        $this->lock?->refresh();
    }
}
