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
use App\Document\DuplicateCheck;
use App\Document\DuplicatePair;
use App\Document\Issue;
use App\Duplicates\DuplicateGroups;
use App\Duplicates\DuplicateHit;
use App\Duplicates\DuplicatesDue;
use App\Duplicates\DuplicateSearch;
use App\Repository\DuplicateRepository;
use App\Repository\IssueRepository;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Finds the possible duplicates among the open issues for the list of them, a step of the Forge import. It works
 * on the indices alone and asks Forge nothing.
 *
 * Each run checks the issues changed since their last check and the new ones, see DuplicatesDue. The similarity
 * is the same from both sides, so a new issue finds its pairs with the older issues by itself. A closed issue
 * changes, too: its check and its pairs are removed. What is left of the limit renews the oldest checks.
 */
final readonly class DuplicateImport
{
    public const string STEP = 'Find possible duplicates among the open issues';

    /**
     * Two searches each: some 200 milliseconds, a share of the run. All open issues in a few hours at first.
     */
    private const int LIMIT = 200;

    /**
     * The words of new issues shift the similarity of the others slowly. After a week a check is renewed.
     */
    private const string STALE_AFTER = '-7 days';

    /**
     * The checks and pairs of this many issues go into one write.
     */
    private const int BATCH_SIZE = 25;

    public function __construct(
        private CommandRunRecorder $recorder,
        private DuplicateSearch $search,
        private IssueRepository $issues,
        private DuplicateRepository $duplicates,
    ) {
    }

    public function run(ImportRun $run, \DateTimeImmutable $now): void
    {
        $this->recorder->progress(self::STEP);
        $staleBefore = self::date($now->modify(self::STALE_AFTER));
        $due = DuplicatesDue::of(
            $this->issues->openUpdatedOn(),
            $this->duplicates->checks(),
            $staleBefore,
            self::LIMIT,
        );

        $this->duplicates->remove($due->remove);
        $run->writeln(sprintf(
            'Checking %d issues for duplicates, %d changed or new in all; removed %d no longer open',
            count($due->check),
            $due->pending,
            count($due->remove),
        ));
        if ($due->pending > count($due->check)) {
            $this->recorder->note(sprintf(
                '%s issues left to check for duplicates, the next runs go on.',
                number_format($due->pending - count($due->check)),
            ));
        }

        $bar = $run->progressBar(count($due->check));
        foreach (array_chunk($due->check, self::BATCH_SIZE) as $batch) {
            $this->check($this->issues->byIds($batch)->toArray(), self::date($now));
            foreach ($batch as $ignored) {
                $run->advance($bar, self::STEP);
            }
            $run->refreshLock();
        }
        $run->finish($bar);
    }

    /**
     * @param array<int, Issue> $issues by their number
     */
    private function check(array $issues, string $checkedOn): void
    {
        $checks = [];
        $pairs = [];
        foreach ($issues as $issue) {
            $checks[] = new DuplicateCheck($issue->id, (string) $issue->updatedOn, $checkedOn);
            foreach ($this->offered($issue) as $hit) {
                $pair = DuplicatePair::of($issue->id, $hit, $checkedOn);
                $pairs[$pair->id()] = $pair;
            }
        }
        $this->duplicates->replace($checks, array_values($pairs));
    }

    /**
     * The hits the duplicates page offers as similar. Those Forge already relates are known and left out.
     *
     * @return list<DuplicateHit>
     */
    private function offered(Issue $issue): array
    {
        return DuplicateGroups::of($issue, $this->search->find($issue->id), new ArrayCollection())->similar;
    }

    private static function date(\DateTimeImmutable $date): string
    {
        return $date->format('Y/m/d H:i:s');
    }
}
