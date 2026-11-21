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
use App\Duplicates\DuplicateGroups;
use App\Duplicates\DuplicateHit;
use App\Enum\IssueStatusEnum;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class DuplicateGroupsTest extends TestCase
{
    public function testOffersOnlyTheHitsThatReachTheMinimum(): void
    {
        $groups = DuplicateGroups::of(
            self::issue(91089),
            self::hits([91100 => 45, 91200 => 40, 91300 => 39]),
            new ArrayCollection(),
        );

        self::assertSame([91100, 91200], self::ids($groups->similar));
        self::assertSame(2, $groups->offered());
    }

    public function testTheIssuesForgeRelatesComeFirstWithTheirSimilarity(): void
    {
        $issue = new Issue(91089, 'Issue', duplicates: [80001], duplicatedBy: [91500, 99999]);
        $known = new ArrayCollection([80001 => self::issue(80001), 91500 => self::issue(91500)]);
        $groups = DuplicateGroups::of($issue, self::hits([80001 => 12, 91200 => 45]), $known);

        // 99999 is not in the index: another project, or deleted
        self::assertSame(
            [[80001, 12, DuplicateGroups::DUPLICATES], [91500, null, DuplicateGroups::DUPLICATED_BY]],
            array_map(
                static fn (DuplicateHit $hit): array => [$hit->issue->id, $hit->similarity, $hit->relation],
                $groups->marked,
            ),
        );
        self::assertSame([91200], self::ids($groups->similar));
    }

    public function testLeavesOutClosedIssuesForgeRelates(): void
    {
        $issue = new Issue(91089, 'Issue', duplicates: [80001]);
        $closed = new Issue(80001, 'Closed', status: ['id' => IssueStatusEnum::CLOSED->value]);
        $groups = DuplicateGroups::of($issue, [], new ArrayCollection([80001 => $closed]));

        self::assertSame([], $groups->marked);
    }

    private static function issue(int $id): Issue
    {
        return new Issue($id, 'Issue ' . $id);
    }

    /**
     * @param array<int, int> $similarities by the number of the issue
     *
     * @return list<DuplicateHit>
     */
    private static function hits(array $similarities): array
    {
        $hits = [];
        foreach ($similarities as $id => $similarity) {
            $hits[] = new DuplicateHit(self::issue($id), $similarity);
        }

        return $hits;
    }

    /**
     * @param list<DuplicateHit> $hits
     *
     * @return list<int>
     */
    private static function ids(array $hits): array
    {
        return array_map(static fn (DuplicateHit $hit): int => $hit->issue->id, $hits);
    }
}
