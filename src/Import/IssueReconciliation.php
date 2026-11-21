<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

/**
 * Finds where the issues of the index differ from those of Forge by their numbers alone: a range with as many
 * issues on both sides is taken as alike, one that differs is halved until its parts are small enough to list
 * them, see ForgeImport. Each half costs one count at Forge, the other half is the rest of the range.
 *
 * Two differences that make up for each other in a range, one issue missing and one too many, go unnoticed; the
 * delta and the versions of the import cover what changes.
 */
final class IssueReconciliation
{
    /**
     * A range is listed once neither side has more issues: one page of Forge.
     */
    public const int LISTED = 100;

    /**
     * The ranges to list, the highest numbers first: new issues are missed more likely than old ones.
     *
     * @param int                   $from       the lowest number of the range
     * @param int                   $to         the highest number of the range
     * @param int                   $forge      the issues Forge has in the range
     * @param int                   $index      the issues the index has in the range
     * @param callable(int,int):int $countForge
     * @param callable(int,int):int $countIndex
     * @param int                   $budget     counts at Forge at most; the ranges found until then are listed, the
     *                                          next run goes on
     * @param int                   $maxRanges  ranges at most; listing one may load up to LISTED issues, the next
     *                                          run goes on
     *
     * @return list<array{int, int}>
     */
    public static function ranges(
        int $from,
        int $to,
        int $forge,
        int $index,
        callable $countForge,
        callable $countIndex,
        int $budget,
        int $maxRanges = PHP_INT_MAX,
    ): array {
        $ranges = [];
        $pending = [[$from, $to, $forge, $index]];
        while ([] !== $pending) {
            [$low, $high, $forgeCount, $indexCount] = array_pop($pending);
            if ($forgeCount === $indexCount) {
                continue;
            }
            if (($forgeCount <= self::LISTED && $indexCount <= self::LISTED) || $low === $high) {
                $ranges[] = [$low, $high];
                if (count($ranges) >= $maxRanges) {
                    break;
                }
                continue;
            }
            if ($budget <= 0) {
                break;
            }
            --$budget;
            $middle = intdiv($low + $high, 2);
            $forgeLow = $countForge($low, $middle);
            $indexLow = $countIndex($low, $middle);
            // The higher half last, so it is taken first
            $pending[] = [$low, $middle, $forgeLow, $indexLow];
            $pending[] = [$middle + 1, $high, $forgeCount - $forgeLow, $indexCount - $indexLow];
        }

        return $ranges;
    }
}
