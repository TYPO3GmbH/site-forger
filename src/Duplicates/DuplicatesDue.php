<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Duplicates;

use App\Document\DuplicateCheck;

/**
 * Which issues DuplicateImport checks in a run, and which it removes from the duplicate index.
 */
final readonly class DuplicatesDue
{
    /**
     * @param list<int> $check   the issues to check, in this order, at most the limit
     * @param list<int> $remove  the issues no longer open or gone from the index
     * @param int       $pending how many changed or new issues wait for a check, this run's ones included
     */
    public function __construct(
        public array $check,
        public array $remove,
        public int $pending,
    ) {
    }

    /**
     * The changed and new issues come first, those changed last first. The rest of the limit goes to the checks
     * older than $staleBefore, the oldest first: the words of other issues shift the similarity slowly.
     *
     * @param array<int, string>         $open        "updated_on" of every open issue, by its number
     * @param array<int, DuplicateCheck> $checks      by the number of the issue
     * @param string                     $staleBefore in the format of "checked_on"
     */
    public static function of(array $open, array $checks, string $staleBefore, int $limit): self
    {
        $changed = [];
        $stale = [];
        foreach ($open as $id => $updated) {
            $check = $checks[$id] ?? null;
            if (null === $check || $check->issueUpdatedOn !== $updated) {
                $changed[$id] = $updated;
            } elseif ($check->checkedOn < $staleBefore) {
                $stale[$id] = $check->checkedOn;
            }
        }
        arsort($changed);
        asort($stale);
        $due = [...array_keys($changed), ...array_keys($stale)];

        return new self(
            array_map(intval(...), array_slice($due, 0, max(0, $limit))),
            array_map(intval(...), array_keys(array_diff_key($checks, $open))),
            count($changed),
        );
    }
}
