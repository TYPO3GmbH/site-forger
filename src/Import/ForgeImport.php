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
use App\Elastic\Indices;
use Symfony\Component\Console\Helper\ProgressBar;

final readonly class ForgeImport
{
    public const string LOCK = 'forge';

    public const string STEP_FULL = 'Compare all Forge issues and update the outdated ones';
    public const string STEP_VANISHED = 'Remove issues gone from Forge';
    public const string STEP_DELTA = 'Import the issues changed since the last run';
    public const string STEP_LATEST = 'Import the latest issues, the index is empty';
    public const string STEP_OUTDATED = 'Load issues of an older import again';
    public const string STEP_RECONCILE = 'Compare the issues with Forge';

    /**
     * The next run imports the rest.
     */
    private const int DELTA_LIMIT = 2000;

    /**
     * One request per issue, a hundred every five minutes: all issues in about two days. More runs into the rate
     * limit of Forge, see the pause of api.forge in config/services.yaml.
     */
    private const int OUTDATED_LIMIT = 100;

    /**
     * Each halving of the ranges costs one count. About a dozen find a single issue among all of them.
     */
    private const int RECONCILE_COUNTS = 15;

    /**
     * Each range loads up to a page of issues, one request each: two keep a run within its five minutes when the
     * index lacks many issues. The next run goes on.
     */
    private const int RECONCILE_RANGES = 2;

    public function __construct(
        private CommandRunRecorder $recorder,
        private ImportWatermark $watermark,
        private ProjectIssues $projectIssues,
        private ForgeIssueImporter $issueImporter,
        private ForgeIssueList $forgeIssues,
    ) {
    }

    /**
     * Lists all issues, newest first, and imports those that are not up to date in the index. This is the safety
     * net for what the deltas missed.
     */
    public function full(ImportRun $run, int $startAt): void
    {
        $this->removeOtherProjects($run);
        $offset = max(0, $startAt);
        $page = $this->forgeIssues->page($offset);
        $total = $page['total_count'];
        $bar = $run->progressBar(max(0, $total - $offset));
        $skipped = 0;
        $listed = [];
        while ($offset < $total && [] !== $page['issues']) {
            foreach ($page['issues'] as $issue) {
                $listed[(string) $issue['id']] = true;
            }
            $skipped += $this->importIssues($run, $page['issues'], self::STEP_FULL, $bar, true);
            $offset += ForgeIssueList::PAGE_SIZE;
            if ($offset < $total) {
                $page = $this->forgeIssues->page($offset);
            }
        }
        $run->finish($bar);
        $run->writeln(sprintf('%d issues up to date in the index, skipped', $skipped));

        if (0 === $startAt && $offset >= $total) {
            $this->removeVanished($run, $listed);
        }
    }

    public function delta(ImportRun $run): void
    {
        $this->removeOtherProjects($run);
        $upToDate = $this->importChanged($run);
        $this->importOutdated($run);
        // While the delta catches up, the numbers differ anyway
        if ($upToDate) {
            $this->reconcile($run);
        }
    }

    /**
     * Oldest first: a run that ends early leaves the index at a consistent point, and the next run continues from
     * there.
     *
     * @return bool whether the index is up to date afterwards
     */
    private function importChanged(ImportRun $run): bool
    {
        $since = $this->watermark->since(Indices::ISSUE);
        if (null === $since) {
            $this->recorder->note('The issue index is empty: this run imported the latest issues only. "app:import:forge --full" fills it.');
            $this->importIssues($run, $this->forgeIssues->page(0)['issues'], self::STEP_LATEST, null, false);

            return false;
        }

        $filter = ImportWatermark::redmineFilter($since);
        $page = $this->forgeIssues->page(0, $filter);
        $total = $page['total_count'];
        $limit = min($total, self::DELTA_LIMIT);
        $run->writeln(sprintf('%d issues changed since %s, importing %d', $total, $since->format('Y-m-d H:i:s'), $limit));
        if ($total > self::DELTA_LIMIT) {
            $this->recorder->note(sprintf(
                'Catching up: %s issues changed since %s, more than a run imports. This run imports the oldest %s, the next runs go on from there.',
                number_format($total),
                $since->format('j M Y'),
                number_format(self::DELTA_LIMIT),
            ));
        }

        $bar = $run->progressBar($limit);
        $unchanged = 0;
        for ($offset = 0; $offset < $limit && [] !== $page['issues']; $offset += ForgeIssueList::PAGE_SIZE) {
            $unchanged += $this->importIssues($run, array_slice($page['issues'], 0, $limit - $offset), self::STEP_DELTA, $bar, true);
            if ($offset + ForgeIssueList::PAGE_SIZE < $limit) {
                $page = $this->forgeIssues->page($offset + ForgeIssueList::PAGE_SIZE, $filter);
            }
        }
        $run->finish($bar);
        $run->writeln(sprintf('%d of them unchanged since the last import, skipped', $unchanged));

        return $total <= self::DELTA_LIMIT;
    }

    /**
     * Finds what no list of changes shows: issues deleted on Forge or private now, and issues the index never had.
     * One request when the counts match. Otherwise IssueReconciliation narrows down the ranges that differ.
     */
    private function reconcile(ImportRun $run): void
    {
        $forge = $this->forgeIssues->count();
        $index = $this->projectIssues->count();
        if ($forge['count'] === $index) {
            $run->writeln(sprintf('%d issues on Forge and in the index', $index));

            return;
        }
        $run->writeln(sprintf('%d issues on Forge, %d in the index, comparing them by their numbers', $forge['count'], $index));
        $ranges = IssueReconciliation::ranges(
            1,
            max($forge['highest'] ?? 0, $this->projectIssues->highestId()),
            $forge['count'],
            $index,
            fn (int $from, int $to): int => $this->forgeIssues->count($from, $to)['count'],
            fn (int $from, int $to): int => $this->projectIssues->count($from, $to),
            self::RECONCILE_COUNTS,
            self::RECONCILE_RANGES,
        );
        $removed = 0;
        foreach ($ranges as $position => [$from, $to]) {
            $this->recorder->progress(self::STEP_RECONCILE, $position + 1, count($ranges));
            $issues = $this->forgeIssues->range($from, $to);
            $this->importIssues($run, $issues, self::STEP_RECONCILE, null, true);
            $missing = array_diff($this->projectIssues->idsBetween($from, $to), self::ids($issues));
            $removed += $this->issueImporter->remove(array_values($missing));
            $run->refreshLock();
        }
        $run->writeln(sprintf('Compared %d ranges of issues, removed %d issues gone from Forge', count($ranges), $removed));
    }

    /**
     * Each run reloads the next share of the issues an older import wrote, see ImportSchema.
     */
    private function importOutdated(ImportRun $run): void
    {
        ['ids' => $ids, 'total' => $total] = $this->watermark->outdated('issue', self::OUTDATED_LIMIT);
        if ([] === $ids) {
            return;
        }
        $this->reportOutdated($run, $total, count($ids));
        $bar = $run->progressBar(count($ids));
        $removed = $this->reloadOrRemove($run, $ids, self::STEP_OUTDATED, $bar);
        $run->finish($bar);
        if ($removed > 0) {
            $run->writeln(sprintf('Removed %d issues that are gone from Forge', $removed));
        }
    }

    /**
     * The issues the index has but Forge did not list: deleted, private, moved, or missed because the order of the
     * list shifted while the import ran for hours.
     *
     * @param array<string, true> $listed
     */
    private function removeVanished(ImportRun $run, array $listed): void
    {
        $vanished = [];
        foreach ($this->projectIssues->indexedIds() as $id) {
            if (!isset($listed[$id])) {
                $vanished[] = (int) $id;
            }
        }
        $run->writeln(sprintf('%d issues in the index that Forge did not list, checking them', count($vanished)));
        $this->recorder->progress(self::STEP_VANISHED, 0, count($vanished));
        $removed = $this->reloadOrRemove($run, $vanished, self::STEP_VANISHED, null);
        $run->writeln(sprintf('Removed %d issues that are gone from Forge', $removed));
    }

    /**
     * @param list<int> $ids
     *
     * @return int the number of removed issues
     */
    private function reloadOrRemove(ImportRun $run, array $ids, string $step, ?ProgressBar $bar): int
    {
        $afterIssue = static function () use ($run, $bar, $step): void {
            if (null !== $bar) {
                $run->advance($bar, $step);
            }
            $run->refreshLock();
        };

        return $this->issueImporter->update($ids, $afterIssue, $this->skip(...))['removed'];
    }

    /**
     * Skips the issues of other projects and removes them from the index.
     *
     * @param list<array<string, mixed>> $issues
     * @param bool                       $skipUnchanged skip the issues the index has with the same "updated_on"
     *
     * @return int the number of skipped issues
     */
    private function importIssues(ImportRun $run, array $issues, string $step, ?ProgressBar $bar, bool $skipUnchanged): int
    {
        $ofProject = array_filter($issues, $this->projectIssues->belongsToProject(...));
        $otherProjects = array_diff(self::ids($issues), self::ids($ofProject));
        for ($i = count($otherProjects); null !== $bar && $i > 0; --$i) {
            $run->advance($bar, null);
        }
        if (($removed = $this->issueImporter->remove(array_values($otherProjects))) > 0) {
            $run->writeln(sprintf('Removed %d issues of other projects from the index', $removed));
        }

        $changed = $skipUnchanged ? $this->changedIds($ofProject) : null;
        $skipped = 0;
        foreach ($ofProject as $issue) {
            if (null !== $changed && !isset($changed[(string) $issue['id']])) {
                ++$skipped;
            } else {
                $this->issueImporter->tryImport($issue, $this->skip(...));
                $run->refreshLock();
            }
            if (null !== $bar) {
                $run->advance($bar, $step);
            }
        }

        return $skipped;
    }

    /**
     * A failed issue is recorded, and the import continues with the next one.
     *
     * @param array<string, mixed> $issue
     */
    private function skip(array $issue, \Exception $failure): void
    {
        $this->recorder->skipped('issue', (string) ($issue['id'] ?? '?'), $failure);
        // The next delta starts after the issue, the next run loads it again with the older ones
        if (isset($issue['id'])) {
            $this->issueImporter->markOutdated((int) $issue['id']);
        }
    }

    /**
     * @param array<array<string, mixed>> $issues
     *
     * @return array<string, int> the ids of the issues that changed since the index stored them, as keys
     */
    private function changedIds(array $issues): array
    {
        $updated = [];
        foreach ($issues as $issue) {
            $updated[(string) $issue['id']] = ForgeIssueImporter::indexDate((string) $issue['updated_on']);
        }

        return array_flip(ImportWatermark::changed($updated, $this->watermark->storedUpdates('issue', array_keys($updated))));
    }

    private function removeOtherProjects(ImportRun $run): void
    {
        $removed = $this->projectIssues->removeOtherProjects();
        if ($removed > 0) {
            $run->writeln(sprintf('Removed %d issues of other projects from the index', $removed));
        }
    }

    private function reportOutdated(ImportRun $run, int $total, int $loading): void
    {
        $run->writeln(sprintf('%d issues of an older import, loading %d again', $total, $loading));
        $this->recorder->note(sprintf('%s issues of an older import left, each run loads %s again.', number_format(max(0, $total - $loading)), number_format(self::OUTDATED_LIMIT)));
    }

    /**
     * @param array<array<string, mixed>> $issues
     *
     * @return list<int>
     */
    private static function ids(array $issues): array
    {
        return array_values(array_map(static fn (array $issue): int => (int) $issue['id'], $issues));
    }
}
