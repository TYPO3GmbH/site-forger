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
use App\Document\Change;
use App\Elastic\Indices;
use App\Repository\ChangeRepository;
use App\Search\AffectedChangesQuery;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Imports in two steps: a light query lists the changes with their update time, and only the changes that are not
 * up to date in the index are loaded with their details.
 */
final readonly class GerritImport
{
    public const string LOCK = 'gerrit';

    public const string STEP_FULL_LIST = 'Compare all Gerrit changes with the index';
    public const string STEP_FULL_LOAD = 'Load the outdated changes from Gerrit';
    public const string STEP_VANISHED = 'Remove changes gone from Gerrit';
    public const string STEP_DELTA_LIST = 'Find the changes updated since the last run';
    public const string STEP_DELTA_LOAD = 'Load the updated changes from Gerrit';
    public const string STEP_LATEST = 'Load the latest changes, the index is empty';
    public const string STEP_OUTDATED = 'Load changes of an older import again';
    public const string STEP_AFFECTED = 'Load the open changes the updated ones affect';
    public const string STEP_SWEEP_LIST = 'Compare a part of the Gerrit changes with the index';
    public const string STEP_SWEEP_LOAD = 'Load the changes the index lacks';

    private const int DELTA_LIMIT = 3000;

    /**
     * A delta that catches up starts with this window and halves it until its changes fit in a run.
     */
    private const int CATCH_UP_WINDOW = 86400;
    private const int CATCH_UP_MIN_WINDOW = 60;

    /**
     * The seconds a delta may take: the job starts every five minutes. Loading the changes of an older import fills
     * the time the updated ones leave, see importOutdated().
     */
    private const int RUN_SECONDS = 270;

    /**
     * The most changes of an older import a run looks at. The time of the run ends the loading long before.
     */
    private const int OUTDATED_LIMIT = 10000;

    /**
     * The most open changes a run loads again because updated ones affect them, see importAffected(). A merge into
     * a busy folder touches many, the next runs do not catch up on the rest: they stay as they were.
     */
    private const int AFFECTED_LIMIT = 200;

    private const int LATEST_COUNT = 100;

    /**
     * Without details Gerrit sends 500 changes in about the time of 100.
     */
    private const int LIST_PAGE_SIZE = 500;

    public function __construct(
        private CommandRunRecorder $recorder,
        private ImportWatermark $watermark,
        private ProjectChanges $projectChanges,
        private GerritChangeImporter $changeImporter,
        private ChangeRepository $changes,
        private ChangeSweepState $sweepState,
    ) {
    }

    /**
     * Writes the changes batch by batch, because there are more than fit in memory.
     */
    public function full(ImportRun $run, int $startAt): void
    {
        $bar = $run->progressBar();
        // Gerrit does not tell the total. The index has about as many changes.
        [$listed, $numbers, $changed, $more] = $this->list(GerritChangeImporter::PROJECT, null, $startAt, self::STEP_FULL_LIST, $this->projectChanges->count());
        $run->writeln(sprintf('%d changes in Gerrit, %d of them not up to date in the index', $listed, count($changed)));
        $bar->setMaxSteps(count($changed));
        foreach (array_chunk($changed, GerritChangeImporter::DETAIL_BATCH) as $batch) {
            $this->changeImporter->writeAll($this->documents($run, GerritChangeImporter::changes($batch), count($batch), $bar, self::STEP_FULL_LOAD));
        }
        if (0 === $startAt && !$more) {
            $this->removeVanished($run, $numbers, $bar);
        }
    }

    /**
     * Writes the changes only when all of them are loaded. A run that fails half way does not move the watermark,
     * so the next run tries again.
     */
    public function delta(ImportRun $run): void
    {
        $budget = new RunBudget(self::RUN_SECONDS);
        $since = $this->watermark->since(Indices::REVIEW);
        $upToDate = true;
        if (null === $since) {
            $this->recorder->note(
                'The review index is empty: this run imported the latest changes only. The comparison with Gerrit'
                . ' fills it a page each run, "app:import:review --full" at once.',
            );
            $this->changeImporter->writeAll($this->documents(
                $run,
                GerritChangeImporter::PROJECT,
                self::LATEST_COUNT,
                $run->progressBar(),
                self::STEP_LATEST,
            ));
        } else {
            $upToDate = $this->importUpdated($run, $since);
        }
        // While the delta catches up, the comparison would load the changes the next runs bring anyway
        if ($upToDate) {
            $this->sweep($run);
        }
        $this->importOutdated($run, $budget);
    }

    /**
     * @return bool whether the index is up to date afterwards
     */
    private function importUpdated(ImportRun $run, \DateTimeImmutable $since): bool
    {
        $query = GerritChangeImporter::PROJECT . ' ' . ImportWatermark::gerritOperator($since);
        [$listed, , $changed, $more] = $this->list($query, self::DELTA_LIMIT, 0, self::STEP_DELTA_LIST, null);
        if ($more) {
            [$listed, $changed] = $this->catchUp($query, $since);
        }
        $run->writeln(sprintf('%d changes updated since %s, %d of them not up to date in the index', $listed, $since->format('Y-m-d H:i:s'), count($changed)));

        $bar = $run->progressBar(count($changed));
        $documents = [];
        foreach (array_chunk($changed, GerritChangeImporter::DETAIL_BATCH) as $batch) {
            $documents += $this->documents($run, GerritChangeImporter::changes($batch), count($batch), $bar, self::STEP_DELTA_LOAD);
        }
        $this->changeImporter->writeAll($documents);
        $this->importAffected($run, array_values($documents));

        return !$more;
    }

    /**
     * Finds what no delta finds, a part each run, see ChangeSweep: loads the changes the index lacks or has in an
     * older state, and removes those Gerrit does not show any more. The round moves on only when the run is done,
     * so a run that fails compares its part again.
     */
    private function sweep(ImportRun $run): void
    {
        $round = $this->sweepState->round();
        $until = $round->until;
        $query = GerritChangeImporter::PROJECT . (null === $until ? '' : ' ' . ImportWatermark::gerritBefore($until));
        [$listed, $numbers, $changed, $more, $oldest] = $this->list(
            $query,
            ChangeSweep::LISTED,
            0,
            self::STEP_SWEEP_LIST,
            ChangeSweep::LISTED,
            ChangeSweep::LOADED,
        );

        $missing = $this->missing($changed);
        $bar = $run->progressBar(count($changed));
        foreach (array_chunk($changed, GerritChangeImporter::DETAIL_BATCH) as $batch) {
            $this->changeImporter->writeAll($this->documents(
                $run,
                GerritChangeImporter::changes($batch),
                count($batch),
                $bar,
                self::STEP_SWEEP_LOAD,
            ));
        }
        $unlisted = array_values(array_diff(
            $this->projectChanges->numbersOf(ChangeSweep::indexed($oldest, $until, $more)),
            array_keys($numbers),
        ));
        // Asks Gerrit for each first: a change updated since the list was taken is listed on no page
        $removed = 0;
        if ([] !== $unlisted) {
            $removed = $this->changeImporter->update($unlisted, $this->afterChange($run, $bar, null))['removed'];
        }
        $next = $round->after(ChangeSweep::next($until, $oldest, $more), $missing, $removed, new \DateTimeImmutable());
        $this->sweepState->save($next);
        $run->finish($bar);

        $this->noteSweep($run, sprintf(
            'Compared %s changes of Gerrit %s with the index: %s missing, %s loaded, %s removed.',
            number_format($listed),
            null === $until ? 'from the newest' : 'updated before ' . $until->format('j M Y H:i'),
            number_format($missing),
            number_format(count($changed)),
            number_format($removed),
        ));
        if (null === $next->until) {
            $this->noteSweep($run, sprintf(
                'The comparison with Gerrit finished a round: it found %s changes missing and removed %s.'
                . ' The next run starts a new round at the newest change.',
                number_format($next->lastAdded),
                number_format($next->lastRemoved),
            ));
        }
    }

    /**
     * How many of the changes the index lacks. Those it has in an older state are not missing: a delta or an
     * older import wrote them.
     *
     * @param list<string> $changed the numbers of the changes not up to date
     */
    private function missing(array $changed): int
    {
        if ([] === $changed) {
            return 0;
        }

        return count($changed) - count($this->projectChanges->numbersOf(['ids' => ['values' => $changed]]));
    }

    private function noteSweep(ImportRun $run, string $message): void
    {
        $run->writeln($message);
        $this->recorder->note($message);
    }

    /**
     * Loads again the open changes the updated ones affect without updating them in Gerrit: their mergeability,
     * their relation chain, their merge conflicts, see AffectedChangesQuery.
     *
     * @param list<array<string, mixed>> $documents the documents the delta wrote
     */
    private function importAffected(ImportRun $run, array $documents): void
    {
        $request = AffectedChangesQuery::request($documents, self::AFFECTED_LIMIT);
        if (null === $request) {
            return;
        }
        $numbers = $this->changes->search($request)->map(static fn (Change $change): int => $change->id)->getValues();
        if ([] === $numbers) {
            return;
        }
        $run->writeln(sprintf('%d open changes the updated ones affect, loading them again', count($numbers)));
        $bar = $run->progressBar(count($numbers));
        $this->changeImporter->update($numbers, $this->afterChange($run, $bar, self::STEP_AFFECTED));
    }

    /**
     * Gerrit lists the newest changes first. With more changes than a run imports, the newest would move the
     * watermark past the older ones. So the run takes the oldest window that fits instead.
     *
     * @return array{int, list<string>} the number of listed changes and those not up to date
     */
    private function catchUp(string $query, \DateTimeImmutable $since): array
    {
        $listed = 0;
        $changed = [];
        $until = ImportWatermark::catchUpWindow($since, self::CATCH_UP_WINDOW, self::CATCH_UP_MIN_WINDOW, function (\DateTimeImmutable $until) use ($query, &$listed, &$changed): bool {
            [$listed, , $changed, $more] = $this->list($query . ' ' . ImportWatermark::gerritBefore($until), self::DELTA_LIMIT, 0, self::STEP_DELTA_LIST, null);

            return !$more;
        });
        $this->recorder->note(sprintf(
            'Catching up: more than %s changes were updated since %s, more than a run imports. This run imports those until %s, the next runs go on from there.',
            number_format(self::DELTA_LIMIT),
            $since->format('j M Y H:i'),
            $until->format('j M Y H:i'),
        ));

        return [$listed, $changed];
    }

    /**
     * Lists the changes of a query without their details. Returns the number of listed changes, their numbers,
     * those not up to date, whether Gerrit has more, and the update time of the last listed change.
     *
     * @param int|null $limit    at most this many changes, all without a limit
     * @param int|null $expected the expected number of changes, for the progress
     * @param int|null $enough   no further page once this many changes are not up to date
     *
     * @return array{int, array<int, true>, list<string>, bool, \DateTimeImmutable|null}
     */
    private function list(
        string $query,
        ?int $limit,
        int $startAt,
        string $step,
        ?int $expected,
        ?int $enough = null,
    ): array {
        $listed = 0;
        $numbers = [];
        $changed = [];
        $more = true;
        $last = null;
        for (
            $start = max(0, $startAt);
            $more && (null === $limit || $start < $limit) && (null === $enough || count($changed) < $enough);
            $start += self::LIST_PAGE_SIZE
        ) {
            $changes = $this->changeImporter->find($query, self::LIST_PAGE_SIZE, $start, []);
            $updated = [];
            foreach ($changes as $change) {
                $updated[(string) $change['_number']] = GerritChangeImporter::gerritDate($change['updated']);
                $numbers[(int) $change['_number']] = true;
                $last = ChangeSweep::gerritTime((string) $change['updated']);
            }
            $listed += count($updated);
            array_push($changed, ...ImportWatermark::changed($updated, $this->watermark->storedUpdates('review', array_keys($updated))));
            $more = [] !== $changes && array_key_exists('_more_changes', array_last($changes));
            // Past the expected number the total is unknown, so the bar does not stand at 100 % while it lists on
            $this->recorder->progress($step, $listed, null !== $expected && $listed < $expected ? $expected : null);
        }

        return [$listed, $numbers, $changed, $more, $last];
    }

    /**
     * Loads the changes an older import wrote again, see ImportSchema, batch by batch while the run has time left.
     * A run with many updated changes loads few of them, a quiet run many.
     */
    private function importOutdated(ImportRun $run, RunBudget $budget): void
    {
        ['ids' => $numbers, 'total' => $total] = $this->watermark->outdated('review', self::OUTDATED_LIMIT);
        if ([] === $numbers) {
            return;
        }
        $run->writeln(sprintf('%d changes of an older import, loading them while the run has time', $total));
        // The bar counts to the most it may load. The step has no total: the time ends it.
        $bar = $run->progressBar(count($numbers));
        $afterChange = $this->afterChange($run, $bar, null);
        $this->recorder->progress(self::STEP_OUTDATED, 0);
        $loaded = 0;
        $removed = 0;
        $budget->each(
            $numbers,
            GerritChangeImporter::DETAIL_BATCH,
            function (array $batch) use ($afterChange, &$loaded, &$removed): void {
                $removed += $this->changeImporter->update($batch, $afterChange)['removed'];
                $loaded += count($batch);
                $this->recorder->progress(self::STEP_OUTDATED, $loaded);
            },
        );
        $run->finish($bar);
        // The step ends at what it loaded, not at what was left
        $this->recorder->progress(self::STEP_OUTDATED, $loaded, $loaded);
        $this->recorder->note(sprintf(
            '%s changes of an older import left, this run loaded %s again.',
            number_format(max(0, $total - $loaded)),
            number_format($loaded),
        ));
        if ($removed > 0) {
            $run->writeln(sprintf('Removed %d changes that Gerrit does not show any more', $removed));
        }
    }

    /**
     * The changes the index has but Gerrit did not list: private, deleted, or missed because the order of the list
     * shifted while the import ran.
     *
     * @param array<int, true> $listed
     */
    private function removeVanished(ImportRun $run, array $listed, ProgressBar $bar): void
    {
        $vanished = [];
        foreach ($this->projectChanges->indexedNumbers() as $number) {
            if (!isset($listed[$number])) {
                $vanished[] = $number;
            }
        }
        $run->writeln(sprintf('%d changes in the index that Gerrit did not list, checking them', count($vanished)));

        $removed = 0;
        foreach (array_chunk($vanished, GerritChangeImporter::DETAIL_BATCH) as $position => $batch) {
            // The step counts the batches, not the reloaded changes
            $this->recorder->progress(self::STEP_VANISHED, $position * GerritChangeImporter::DETAIL_BATCH + count($batch), count($vanished));
            $removed += $this->changeImporter->update($batch, $this->afterChange($run, $bar, null))['removed'];
        }
        $run->writeln(sprintf('Removed %d changes that Gerrit does not show any more', $removed));
    }

    /**
     * An error ends the import, because an empty result would skip the changes silently.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documents(ImportRun $run, string $query, int $count, ProgressBar $bar, ?string $step): array
    {
        return $this->changeImporter->documents($this->changeImporter->find($query, $count), $this->afterChange($run, $bar, $step));
    }

    /**
     * @return \Closure(): void
     */
    private function afterChange(ImportRun $run, ProgressBar $bar, ?string $step): \Closure
    {
        return static function () use ($run, $bar, $step): void {
            $run->advance($bar, $step);
            $run->refreshLock();
        };
    }
}
