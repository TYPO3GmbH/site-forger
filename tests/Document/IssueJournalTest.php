<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Document;

use App\Document\Issue;
use App\Document\IssueJournal;
use App\Enum\IssueFieldEnum;
use PHPUnit\Framework\TestCase;

final class IssueJournalTest extends TestCase
{
    public function testNamesTheChangedFieldsAndTheirValues(): void
    {
        $journal = IssueJournal::fromJournal([
            'user' => ['id' => 1, 'name' => 'Jane Doe'],
            'created_on' => '2026-11-20T10:00:00Z',
            'details' => [
                ['property' => 'attr', 'name' => 'status_id', 'old_value' => '1', 'new_value' => '8'],
                ['property' => 'cf', 'name' => '4', 'old_value' => '12', 'new_value' => '14'],
                ['property' => 'attachment', 'name' => '38843', 'old_value' => null, 'new_value' => 'screen.png'],
                ['property' => 'relation', 'name' => 'duplicated', 'old_value' => null, 'new_value' => '92367'],
                ['property' => 'attr', 'name' => 'description', 'old_value' => 'Old text', 'new_value' => 'New text'],
            ],
        ]);

        self::assertSame(
            [
                [IssueFieldEnum::STATUS, 'status', 'New', 'Under Review'],
                [IssueFieldEnum::CUSTOM_FIELD, 'TYPO3 Version', '12', '14'],
                [IssueFieldEnum::ATTACHMENT, 'file', null, 'screen.png'],
                [IssueFieldEnum::RELATION, 'Duplicated by', null, '92367'],
                [IssueFieldEnum::DESCRIPTION, 'description', null, null],
            ],
            array_map(
                static fn ($change): array => [$change->field, $change->name, $change->from, $change->to],
                $journal->changes,
            ),
        );
        self::assertSame([92367], $journal->issueNumbers());
        self::assertFalse($journal->hasComment());
    }

    public function testReadsTheChangeOfANoteOfGerrit(): void
    {
        $journal = IssueJournal::fromJournal([
            'user' => ['id' => 2, 'name' => 'Gerrit Code Review'],
            'notes' => 'Patch set 2 for branch *main* of project *Packages/TYPO3.CMS* has been pushed to the review '
                . "server.\nIt is available at https://review.typo3.org/c/Packages/TYPO3.CMS/+/97864",
        ]);

        self::assertTrue($journal->isByBot());
        self::assertFalse($journal->hasComment());
        self::assertSame([97864, 2, 'main'], [$journal->change, $journal->patchSet, $journal->branch]);
    }

    public function testTheHistoryOfAnIssueLeavesOutThePrivateNotes(): void
    {
        $issue = Issue::fromDocument(['id' => 1, 'subject' => 'Issue', 'journals' => [
            ['user' => ['id' => 1, 'name' => 'Jane Doe'], 'notes' => 'Public.'],
            ['user' => ['id' => 1, 'name' => 'Jane Doe'], 'notes' => 'Hidden.', 'private_notes' => true],
        ]]);

        $notes = array_map(static fn (IssueJournal $journal): string => $journal->notes, $issue->history());

        self::assertSame(['Public.'], $notes);
        self::assertTrue($issue->history()[0]->hasComment());
    }

    public function testLeavesOutTheChangesThatNameAnIssueNotInTheList(): void
    {
        $journal = IssueJournal::fromJournal(['user' => ['id' => 1, 'name' => 'Jane Doe'], 'details' => [
            ['property' => 'relation', 'name' => 'relates', 'new_value' => '110316'],
            ['property' => 'attr', 'name' => 'status_id', 'old_value' => '1', 'new_value' => '8'],
        ]]);

        self::assertSame([IssueFieldEnum::STATUS], array_map(
            static fn ($change): IssueFieldEnum => $change->field,
            $journal->withIssuesOf([])->changes ?? [],
        ));
        self::assertCount(2, $journal->withIssuesOf([110316 => true])->changes ?? []);

        $onlyRelation = IssueJournal::fromJournal(['details' => [
            ['property' => 'relation', 'name' => 'relates', 'new_value' => '110316'],
        ]]);
        self::assertNull($onlyRelation->withIssuesOf([]));
    }
}
