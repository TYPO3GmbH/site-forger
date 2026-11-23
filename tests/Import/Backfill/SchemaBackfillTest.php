<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Import\Backfill;

use App\Elastic\Indices;
use App\Import\Backfill\BackfillStep;
use App\Import\Backfill\ChangeClosedOn;
use App\Import\Backfill\ChangeVoters;
use App\Import\Backfill\IssueTags;
use App\Import\Backfill\SchemaBackfill;
use App\Import\Backfill\SchemaStep;
use App\Import\ImportSchema;
use App\Import\PendingImport;
use App\Stream\ChangeActivity;
use App\Stream\IssueActivity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchemaBackfillTest extends KernelTestCase
{
    public function testEveryStepOfTheImportLeadsToAVersionItKnows(): void
    {
        $taken = [];
        foreach ($this->backfill()->steps() as $step) {
            self::assertNotSame([], $step->fields(), $step::class);
            if (!$step instanceof SchemaStep) {
                continue;
            }
            self::assertLessThan(ImportSchema::current($step->index()), $step->from(), $step::class);
            self::assertNotContains([$step->index(), $step->from()], $taken, $step::class);
            $taken[] = [$step->index(), $step->from()];
        }
        self::assertContains([Indices::REVIEW, 4], $taken);
    }

    public function testTheActivityStreamIsAStepOfEachIndex(): void
    {
        $steps = array_map(static fn (BackfillStep $step): string => $step::class, $this->backfill()->steps());

        // By index, the steps of the import before the stream: the events come from the lifted document
        self::assertSame(
            [IssueTags::class, IssueActivity::class, ChangeClosedOn::class, ChangeVoters::class, ChangeActivity::class],
            $steps,
        );
    }

    public function testAStepOfTheImportWritesItsFieldsAndTheNextVersion(): void
    {
        $merge = ['date' => '2024/01/24 21:21:22', 'message' => 'Change has been successfully merged by Jane Doe'];
        $fields = new ChangeClosedOn()->lift([['id' => 7, 'reviewstatus' => 'MERGED', 'messages' => [$merge]]]);

        self::assertSame([7 => ['closed_on' => '2024/01/24 21:21:22', ImportSchema::FIELD => 5]], $fields);
    }

    public function testAVersionIsReachedWhenStepsLeadFromItToTheCurrentOne(): void
    {
        $backfill = $this->backfill();

        self::assertTrue($backfill->reaches(Indices::REVIEW, ImportSchema::REVIEW));
        self::assertTrue($backfill->reaches(Indices::REVIEW, 4));
        // Version 3 has no review log: only Gerrit lifts it
        self::assertFalse($backfill->reaches(Indices::REVIEW, 3));
        self::assertTrue($backfill->reaches(Indices::ISSUE, ImportSchema::ISSUE - 1));
        self::assertFalse($backfill->reaches(Indices::ISSUE, ImportSchema::ISSUE - 2));
    }

    public function testTheStatusTellsWhatLiftsEachVersion(): void
    {
        $versions = PendingImport::versions(Indices::REVIEW, [3 => 10, 5 => 30, 4 => 20, 6 => 40], $this->backfill());

        self::assertSame(
            [
                ['version' => 6, 'documents' => 40, 'state' => 'current'],
                ['version' => 5, 'documents' => 30, 'state' => 'backfill'],
                ['version' => 4, 'documents' => 20, 'state' => 'backfill'],
                ['version' => 3, 'documents' => 10, 'state' => 'import'],
            ],
            $versions,
        );
    }

    public function testTheDayAChangeClosedComesFromItsStoredLog(): void
    {
        $step = new ChangeClosedOn();
        $merge = ['date' => '2024/01/24 21:21:22', 'message' => 'Change has been successfully merged by Jane Doe'];

        self::assertSame(
            ['closed_on' => '2024/01/24 21:21:22'],
            $step->derive(['reviewstatus' => 'MERGED', 'messages' => [$merge]]),
        );
        self::assertSame(['closed_on' => null], $step->derive(['reviewstatus' => 'NEW', 'messages' => []]));
    }

    public function testTheVotersComeFromTheStoredVotes(): void
    {
        $votes = [
            ['label' => 'Code-Review', 'value' => 1, 'account' => ['id' => 7, 'name' => 'Jane Doe']],
            ['label' => 'Verified', 'value' => 1, 'account' => ['id' => 7, 'name' => 'Jane Doe']],
            ['label' => 'Code-Review', 'value' => -1, 'account' => ['id' => 9, 'name' => 'John Roe']],
            ['label' => 'Verified', 'value' => 1, 'account' => null],
        ];

        self::assertSame(['voters' => [7, 9]], new ChangeVoters()->derive(['votes' => $votes]));
        self::assertSame(['voters' => []], new ChangeVoters()->derive([]));
    }

    public function testTheStoredTagsAreSplitAtSpaces(): void
    {
        self::assertSame(
            ['tags' => ['accessibility', 'a11y', 'backend']],
            new IssueTags()->derive(['tags' => ['accessibility a11y', 'backend', 'a11y']]),
        );
        self::assertSame(['tags' => []], new IssueTags()->derive([]));
    }

    public function testDocumentsWithoutAVersionAreOfTheFirst(): void
    {
        self::assertSame(['term' => [ImportSchema::FIELD => 4]], ImportSchema::writtenBy(4));
        self::assertArrayHasKey('should', ImportSchema::writtenBy(1)['bool']);
    }

    private function backfill(): SchemaBackfill
    {
        return self::getContainer()->get(SchemaBackfill::class);
    }
}
