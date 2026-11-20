<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Report;

use App\Document\Change;
use App\Document\Issue;
use App\Enum\ActivityEnum;
use App\Enum\IssueSortEnum;
use App\Enum\ReviewStateEnum;
use App\Report\LinkedChange;
use App\Report\StaleIssue;
use App\Report\StaleIssues;
use PHPUnit\Framework\TestCase;

final class StaleIssuesTest extends TestCase
{
    private static function issue(int $id, int ...$linked): Issue
    {
        $journals = array_map(static fn (int $number): array => [
            'notes' => 'Patch set 1 for branch main has been pushed to the review server. '
                . 'It is available at https://review.typo3.org/c/Packages/TYPO3.CMS/+/' . $number,
        ], $linked);

        return Issue::fromDocument(['id' => $id, 'journals' => $journals]);
    }

    public function testTheIssuesOfTheStagesStaleAndOrphanedThoseUntouchedTheLongestFirst(): void
    {
        $request = StaleIssues::issuesRequest(3, 25);

        // ActivityEnum decides, also which issues count as open
        self::assertSame([ActivityEnum::STALE->issueQuery(), ActivityEnum::ORPHANED->issueQuery()], $request['query']['bool']['should']);
        self::assertSame(1, $request['query']['bool']['minimum_should_match']);
        self::assertSame([['updated_on' => 'asc'], ['id' => 'asc']], $request['sort']);
        // A page at a time, the total of all pages counted
        self::assertSame(50, $request['from']);
        self::assertSame(25, $request['size']);
        self::assertTrue($request['track_total_hits']);
        self::assertSame(0, StaleIssues::issuesRequest(0, 25)['from']);
        // Another order, the id still last
        self::assertSame([['created_on' => 'desc'], ['id' => 'asc']], StaleIssues::issuesRequest(1, 25, IssueSortEnum::NEWEST)['sort']);
    }

    public function testEveryIssueStaysWithTheChangesFromTheJournalAndTheCommitMessage(): void
    {
        $issues = [self::issue(1, 81234), self::issue(2), self::issue(3, 81236), self::issue(4)];
        $changes = [
            ['id' => 81234, 'reviewstatus' => 'NEW'],
            // Not linked in the journal of issue 2, but its commit message resolves it
            ['id' => 81235, 'reviewstatus' => 'NEW', 'work_in_progress' => true, 'resolves' => [2]],
            ['id' => 81236, 'reviewstatus' => 'MERGED'],
            ['id' => 81237, 'reviewstatus' => 'ABANDONED', 'related' => [3]],
        ];

        $rows = StaleIssues::withChanges($issues, array_map(Change::fromDocument(...), $changes));

        // An open change does not keep an issue out: it lies still all the same
        self::assertSame([1, 2, 3, 4], array_map(static fn (StaleIssue $row): int => $row->issue->id, $rows));
        self::assertEquals([new LinkedChange(81234, ReviewStateEnum::READY)], $rows[0]->changes->toArray());
        self::assertEquals(
            [new LinkedChange(81235, ReviewStateEnum::WORK_IN_PROGRESS)],
            $rows[1]->changes->toArray(),
        );
        self::assertEquals(
            [new LinkedChange(81236, ReviewStateEnum::MERGED), new LinkedChange(81237, ReviewStateEnum::ABANDONED)],
            $rows[2]->changes->toArray(),
        );
        self::assertTrue($rows[3]->changes->isEmpty());
    }

    public function testAChangeForgerDoesNotHaveHasNoState(): void
    {
        $rows = StaleIssues::withChanges([self::issue(1, 67256)], []);

        self::assertEquals([new LinkedChange(67256, null)], $rows[0]->changes->toArray());
    }

    public function testOneSearchForTheChangesOfAllIssues(): void
    {
        self::assertNull(StaleIssues::changesRequest([]));

        $request = StaleIssues::changesRequest([self::issue(1, 81234, 81235), self::issue(2, 81235)]);
        self::assertNotNull($request);
        self::assertSame(
            [['terms' => ['resolves' => [1, 2]]], ['terms' => ['related' => [1, 2]]], ['terms' => ['id' => [81234, 81235]]]],
            $request['query']['bool']['should'],
        );
        self::assertSame(1, $request['query']['bool']['minimum_should_match']);
    }
}
