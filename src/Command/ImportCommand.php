<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Command;

use App\Elastic\ImportWatermark;
use App\Elastic\IndexSetup;
use App\Elastic\ProjectChanges;
use App\Elastic\ProjectIssues;
use App\Service\CommandRunRecorder;
use App\Service\ForgeIssueImporter;
use App\Service\GerritChangeImporter;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Redmine\Client\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(name: 'app:import', description: 'Imports data from Redmine (forge.typo3.org) and Gerrit (review.typo3.org)')]
class ImportCommand extends Command implements LoggerAwareInterface
{
    use LockableTrait;
    use LoggerAwareTrait;
    /**
     * At most this many issues or changes per delta run. The rest follows in the next run (Forge),
     * or with the weekly reviewFull (Gerrit, which only lists the newest changes first).
     */
    private const int DELTA_LIMIT_FORGE = 2000;
    private const int DELTA_LIMIT_GERRIT = 3000;
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
        private readonly Client $forgeClient,
        private readonly IndexSetup $indexSetup,
        private readonly CommandRunRecorder $recorder,
        private readonly ImportWatermark $watermark,
        private readonly ProjectIssues $projectIssues,
        private readonly ForgeIssueImporter $issueImporter,
        private readonly GerritChangeImporter $changeImporter,
        private readonly ProjectChanges $projectChanges,
    ) {
        parent::__construct();
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
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                '(mode=forgeFull, reviewFull only) Import every issue or change, also those the index has in their current state, e.g. after a change of what is imported'
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
        $force = (bool) $input->getOption('force');
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
                    if ($this->lock('forge')) {
                        try {
                            $this->removeIssuesOfOtherProjects();
                            $this->importFromForge($startAt, $force);
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
                    if ($this->lock('forge')) {
                        try {
                            $this->removeIssuesOfOtherProjects();
                            $this->importForgeDelta();
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
                    if ($this->lock('gerrit')) {
                        try {
                            $this->importReviewDelta($output);
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
                    if ($this->lock('gerrit')) {
                        try {
                            $this->importReviews($output, null, $force, $startAt);
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
        } catch (\Exception $exception) {
            // If any exception occurs, release the lock, else the process will run never again.
            $this->logger->error($exception->getMessage());
            $this->recorder->fail($exception::class . ': ' . $exception->getMessage());
            $output->writeln('Release lock because of exception: ' . $exception->getMessage());
            $this->release();

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Goes through every issue, newest first. Only the issues the index does not have in their current state
     * are imported (journals, user, write), unless forced: the full import is a safety net for what the deltas
     * missed and costs little more than listing the issues.
     *
     * After a complete listing the issues the index has but Forge did not list are checked one by one, see
     * removeVanishedIssues().
     */
    private function importFromForge(int $startOffset, bool $force): void
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
            $skipped += $this->importIssues($page['issues'], 'Forge issues', $progressBar, !$force);
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
     * Issues the index has but Forge did not list: deleted on Forge, moved to another project, or only missed,
     * as the order of the list shifts while the import runs for hours. Each of them is asked for on its own:
     * gone or in another project, it is removed from the index, otherwise imported again.
     *
     * @param array<string, true> $listed the ids Forge listed
     */
    private function removeVanishedIssues(array $listed): void
    {
        $vanished = [];
        foreach ($this->projectIssues->indexedIds() as $id) {
            if (!isset($listed[$id])) {
                $vanished[] = $id;
            }
        }
        $this->output->writeln(sprintf('%d issues in the index that Forge did not list, checking them one by one', count($vanished)));

        $remove = [];
        foreach ($vanished as $position => $id) {
            $this->recorder->progress('Checking issues Forge did not list', $position + 1, count($vanished));
            try {
                $issue = $this->callRedmine(fn () => $this->forgeClient->getApi('issue')->show((int) $id));
            } catch (\RuntimeException $e) {
                if (in_array($e->getCode(), [403, 404], true)) {
                    // Deleted on Forge
                    $remove[] = $id;
                    continue;
                }
                $this->recorder->warning(sprintf('Forge issue #%s could not be checked: %s', $id, $e->getMessage()));
                continue;
            } finally {
                usleep(50000);
                $this->refreshLock();
            }
            if (is_array($issue) && isset($issue['issue'])) {
                // importIssues() removes it if it is in another project, and imports it otherwise
                $this->importIssues([$issue['issue']], 'Checking issues Forge did not list', null, false);
            }
        }
        $removed = $this->projectIssues->remove($remove);
        $this->output->writeln(sprintf('Removed %d issues that are gone from Forge', $removed));
    }

    /**
     * Imports the issues changed since the latest change in the index, oldest first: a run that ends early
     * (limit, error) leaves the index at a consistent point, and the next run goes on from there.
     */
    private function importForgeDelta(): void
    {
        $since = $this->watermark->since('issue');
        if (null === $since) {
            // An empty index needs forgeFull, until then the latest issues at least
            $this->recorder->warning('The issue index is empty, run --mode=forgeFull. Imported the latest issues only.');
            $this->importIssues($this->getAllForgeIssuesPaginated(self::PAGE_SIZE, 0)['issues'] ?? [], 'Latest Forge issues', null, false);

            return;
        }

        $filter = ImportWatermark::redmineFilter($since);
        $first = $this->getAllForgeIssuesPaginated(self::PAGE_SIZE, 0, $filter);
        $total = (int) ($first['total_count'] ?? 0);
        $limit = min($total, self::DELTA_LIMIT_FORGE);
        $this->output->writeln(sprintf('%d issues changed since %s, importing %d', $total, $since->format('Y-m-d H:i:s'), $limit));
        if ($total > self::DELTA_LIMIT_FORGE) {
            $this->recorder->warning(sprintf('%d issues changed since %s, imported the oldest %d, the next run goes on', $total, $since->format('Y-m-d H:i:s'), self::DELTA_LIMIT_FORGE));
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
            $unchanged += $this->importIssues(array_slice($page['issues'], 0, $limit - $offset), 'Changed Forge issues', $progressBar, true);
        }
        $progressBar->finish();
        $this->output->writeln('');
        $this->output->writeln(sprintf('%d of them unchanged since the last import, skipped', $unchanged));
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
            } catch (\Exception $e) {
                // The import goes on with the next issue
                $this->recorder->warning(sprintf('Forge issue #%s: %s', $doc['id'] ?? '?', $e->getMessage()));
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
            $this->recorder->warning('The review index is empty, run --mode=reviewFull. Imported the latest changes only.');
            $progressBar = new ProgressBar($output->section());
            $this->changeImporter->writeAll($this->reviewBlock(GerritChangeImporter::PROJECT, self::PAGE_SIZE, $progressBar));

            return;
        }
        $this->importReviews($output, $since, false);
    }

    /**
     * Imports the Gerrit changes in two steps: a light query lists the changes with their update time (all of
     * them, or those updated since), only those the index does not have in this state are loaded with their
     * revisions, files and messages, unless forced.
     *
     * Gerrit lists the newest changes first. The delta writes the changes once all of them are loaded, so a run
     * that fails half way does not move the latest change in the index, and the next run tries again. The full
     * import writes them batch by batch, it handles far more changes than fit in memory.
     *
     * After a complete listing the full import checks the changes the index has but Gerrit did not list, see
     * removeVanishedChanges().
     */
    private function importReviews(OutputInterface $output, ?\DateTimeImmutable $since, bool $force, int $startAt = 0): void
    {
        $delta = null !== $since;
        $progressBar = new ProgressBar($output->section());
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        // Step 1: which changes are not up to date in the index, without their details
        $query = GerritChangeImporter::PROJECT . ($delta ? ' ' . ImportWatermark::gerritOperator($since) : '');
        $listed = 0;
        $numbers = [];
        $changed = [];
        $more = true;
        for ($start = max(0, $startAt); $more && (!$delta || $start < self::DELTA_LIMIT_GERRIT); $start += self::GERRIT_LIST_PAGE_SIZE) {
            $changes = $this->changeImporter->find($query, self::GERRIT_LIST_PAGE_SIZE, $start, []);
            $updated = [];
            foreach ($changes as $change) {
                $updated[(string) $change['_number']] = $this->fixDateFormat($change['updated'], 'Gerrit');
                $numbers[(int) $change['_number']] = true;
            }
            $listed += count($updated);
            array_push($changed, ...($force ? array_map('strval', array_keys($updated)) : ImportWatermark::changed($updated, $this->watermark->storedUpdates('review', array_keys($updated)))));
            $more = [] !== $changes && array_key_exists('_more_changes', array_last($changes));
            $this->recorder->progress('Listing Gerrit changes', $listed);
            $this->refreshLock();
        }
        if ($delta && $more) {
            $this->recorder->warning(sprintf('More than %d changes were updated since %s, older ones are missing until the next reviewFull', self::DELTA_LIMIT_GERRIT, $since->format('Y-m-d H:i:s')));
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
            $block = $this->reviewBlock($batchQuery, count($batch), $progressBar);
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
            $this->recorder->progress('Checking changes Gerrit did not list', $position * self::GERRIT_DETAIL_BATCH + count($batch), count($vanished));
            $query = GerritChangeImporter::PROJECT . ' (' . implode(' OR ', array_map(static fn (int $number): string => 'change:' . $number, $batch)) . ')';
            $shown = [];
            foreach ($this->changeImporter->find($query, count($batch), 0, []) as $change) {
                $shown[] = (int) $change['_number'];
            }
            if ([] !== $shown) {
                $this->changeImporter->writeAll($this->reviewBlock(implode(' OR ', array_map(static fn (int $number): string => 'change:' . $number, $shown)), count($shown), $progressBar));
            }
            array_push($remove, ...array_diff($batch, $shown));
            $this->refreshLock();
        }
        $removed = $this->projectChanges->remove(array_values($remove));
        $this->output->writeln(sprintf('Removed %d changes that Gerrit does not show any more', $removed));
    }

    /**
     * The documents of the changes a query finds, with the progress of the import.
     * Errors end the import: an empty result would silently skip the changes of this block.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reviewBlock(string $query, int $count, ProgressBar $progressBar): array
    {
        return $this->changeImporter->documents($this->changeImporter->find($query, $count), function () use ($progressBar): void {
            $progressBar->advance();
            $this->reportProgress('Gerrit changes', $progressBar);
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
    private function callRedmine(callable $callback): mixed
    {
        return $this->issueImporter->call($callback);
    }

    /**
     * Gives Forge some time to relax after a rate limit, longer with each attempt.
     */
    private function waitForForge(int $attempt): void
    {
        $sleepTime = min(10 * 2 ** ($attempt - 1), 300);
        $this->logger->warning(sprintf('[RECV] Failed to fetch data from Forge, response code 429, retrying in %d seconds, attempt #%d...', $sleepTime, $attempt));
        $this->recorder->progress(sprintf('Forge rate limit, waiting %d s (attempt %d of %d)', $sleepTime, $attempt, self::FORGE_RETRIES));
        sleep($sleepTime);
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
        ];
        if (null !== $updatedOn) {
            $parameters['updated_on'] = $updatedOn;
        }

        return $this->callRedmine(fn () => $this->forgeClient->getApi('issue')->list($parameters));
    }

    /**
     * Gives Gerrit some time after a rate limit or an error, as long as it asks for or longer with each attempt.
     */
    private function waitForGerrit(int $status, int $seconds, int $attempt): void
    {
        $this->logger->warning(sprintf('Gerrit responded with %d, retrying in %d seconds (attempt %d)...', $status, $seconds, $attempt));
        sleep($seconds);
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
