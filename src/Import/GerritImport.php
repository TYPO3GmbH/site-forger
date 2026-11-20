<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\Elastic\ImportWatermark;
use App\Elastic\ProjectChanges;
use App\Service\CommandRunRecorder;
use App\Service\GerritChangeImporter;
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

    private const int DELTA_LIMIT = 3000;

    /**
     * A delta that catches up starts with this window and halves it until its changes fit in a run.
     */
    private const int CATCH_UP_WINDOW = 86400;
    private const int CATCH_UP_MIN_WINDOW = 60;

    /**
     * Twelve requests every five minutes: all changes in a day.
     */
    private const int OUTDATED_LIMIT = 300;

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
        $since = $this->watermark->since('review');
        if (null === $since) {
            $this->recorder->note('The review index is empty: this run imported the latest changes only. "app:import:review --full" fills it.');
            $this->changeImporter->writeAll($this->documents($run, GerritChangeImporter::PROJECT, self::LATEST_COUNT, $run->progressBar(), self::STEP_LATEST));
        } else {
            $this->importUpdated($run, $since);
        }
        $this->importOutdated($run);
    }

    private function importUpdated(ImportRun $run, \DateTimeImmutable $since): void
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
     * Lists the changes of a query without their details.
     *
     * @param int|null $limit    at most this many changes, all without a limit
     * @param int|null $expected the expected number of changes, for the progress
     *
     * @return array{int, array<int, true>, list<string>, bool} the number of listed changes, their numbers, those not
     *                                                          up to date, and whether Gerrit has more
     */
    private function list(string $query, ?int $limit, int $startAt, string $step, ?int $expected): array
    {
        $listed = 0;
        $numbers = [];
        $changed = [];
        $more = true;
        for ($start = max(0, $startAt); $more && (null === $limit || $start < $limit); $start += self::LIST_PAGE_SIZE) {
            $changes = $this->changeImporter->find($query, self::LIST_PAGE_SIZE, $start, []);
            $updated = [];
            foreach ($changes as $change) {
                $updated[(string) $change['_number']] = GerritChangeImporter::gerritDate($change['updated']);
                $numbers[(int) $change['_number']] = true;
            }
            $listed += count($updated);
            array_push($changed, ...ImportWatermark::changed($updated, $this->watermark->storedUpdates('review', array_keys($updated))));
            $more = [] !== $changes && array_key_exists('_more_changes', array_last($changes));
            // Past the expected number the total is unknown, so the bar does not stand at 100 % while it lists on
            $this->recorder->progress($step, $listed, null !== $expected && $listed < $expected ? $expected : null);
        }

        return [$listed, $numbers, $changed, $more];
    }

    /**
     * Each run reloads the next share of the changes an older import wrote, see ImportSchema.
     */
    private function importOutdated(ImportRun $run): void
    {
        ['ids' => $numbers, 'total' => $total] = $this->watermark->outdated('review', self::OUTDATED_LIMIT);
        if ([] === $numbers) {
            return;
        }
        $run->writeln(sprintf('%d changes of an older import, loading %d again', $total, count($numbers)));
        $this->recorder->note(sprintf('%s changes of an older import left, each run loads %s again.', number_format(max(0, $total - count($numbers))), number_format(self::OUTDATED_LIMIT)));

        $bar = $run->progressBar(count($numbers));
        $removed = $this->changeImporter->update($numbers, $this->afterChange($run, $bar, self::STEP_OUTDATED))['removed'];
        if ($removed > 0) {
            $run->writeln(sprintf('Removed %d changes that Gerrit does not show any more', $removed));
        }
        $run->finish($bar);
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
