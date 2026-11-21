<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Duplicates;

use App\Document\Issue;
use App\Duplicates\DuplicateHit;
use App\Duplicates\DuplicateSearch;
use PHPUnit\Framework\TestCase;

final class DuplicateSearchTest extends TestCase
{
    public function testRatesTheHitsByTheScoreOfTheIssueItself(): void
    {
        $ranked = DuplicateSearch::rank(91089, [
            self::scored(91089, 20.0),
            self::scored(91100, 9.0),
            self::scored(91200, 4.5),
            self::scored(91300, 1.0),
        ]);

        $similarities = [];
        foreach ($ranked as $hit) {
            $similarities[$hit->issue->id] = $hit->similarity;
        }

        self::assertSame([91100 => 45, 91200 => 23, 91300 => 5], $similarities);
    }

    public function testNeverRatesAHitAboveTheIssueItself(): void
    {
        $ranked = DuplicateSearch::rank(91089, [self::scored(91100, 30.0), self::scored(91089, 20.0)]);

        self::assertSame(100, $ranked[0]->similarity);
    }

    public function testFindsNothingWithoutTheIssueItself(): void
    {
        // An issue without a word that sets it apart: the query has no term and does not even find the issue
        self::assertSame([], DuplicateSearch::rank(91089, [self::scored(91100, 3.0)]));
        self::assertSame([], DuplicateSearch::rank(91089, []));
    }

    public function testChecksEachHitFromItsOwnSide(): void
    {
        $forward = DuplicateSearch::rank(91089, [
            self::scored(91089, 20.0),
            self::scored(91100, 12.0),
            self::scored(91200, 10.0),
        ]);
        // 91100 is long: it finds the short 91089 with little of its own score. 91200 does not find it at all
        $checked = DuplicateSearch::check(91089, $forward, [
            [self::scored(91100, 40.0), self::scored(91089, 8.0)],
            [self::scored(91200, 10.0)],
        ]);

        self::assertSame(
            [[91100, 40, 60, 20], [91200, 25, 50, 0]],
            array_map(
                static fn (DuplicateHit $hit): array => [
                    $hit->issue->id,
                    $hit->similarity,
                    $hit->forward,
                    $hit->backward,
                ],
                $checked,
            ),
        );
    }

    public function testTheMostSimilarComeFirstAfterTheCheck(): void
    {
        $forward = DuplicateSearch::rank(91089, [
            self::scored(91089, 10.0),
            self::scored(91100, 8.0),
            self::scored(91200, 6.0),
        ]);
        $checked = DuplicateSearch::check(91089, $forward, [
            [self::scored(91100, 10.0), self::scored(91089, 1.0)],
            [self::scored(91200, 10.0), self::scored(91089, 9.0)],
        ]);

        self::assertSame([91200, 91100], array_map(static fn (DuplicateHit $hit): int => $hit->issue->id, $checked));
    }

    /**
     * @return array{Issue, float}
     */
    private static function scored(int $id, float $score): array
    {
        return [new Issue($id, 'Issue ' . $id), $score];
    }
}
