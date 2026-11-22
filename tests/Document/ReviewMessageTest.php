<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Document;

use App\Document\ReviewMessage;
use App\Enum\ReviewEventEnum;
use App\Enum\ReviewLabelEnum;
use App\Enum\ReviewVoteEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewMessageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ReviewEventEnum, string}>
     */
    public static function events(): iterable
    {
        yield 'a new patch set' => ['Uploaded patch set 2.', ReviewEventEnum::UPLOADED, ''];
        yield 'a rebase' => ['Uploaded patch set 4: Patch Set 3 was rebased.', ReviewEventEnum::REBASED, ''];
        yield 'a rebase in the web' => ['Patch Set 3: Patch Set 2 was rebased', ReviewEventEnum::REBASED, ''];
        yield 'a commit message' => ['Patch Set 2: Commit message was updated.', ReviewEventEnum::MESSAGE_UPDATED, ''];
        yield 'a merge' => ['Change has been successfully cherry-picked as eaf1c62', ReviewEventEnum::MERGED, ''];
        yield 'ready' => ['Set Ready For Review', ReviewEventEnum::READY, ''];
        yield 'abandoned' => ['Abandoned', ReviewEventEnum::ABANDONED, ''];
        $oldAbandon = "Patch Set 1: Abandoned\n\nTYPO3 4.6 branch is closed";
        yield 'abandoned before 2013' => [$oldAbandon, ReviewEventEnum::ABANDONED, 'TYPO3 4.6 branch is closed'];
        yield 'restored before 2013' => ['Patch Set 2: Restored', ReviewEventEnum::RESTORED, ''];
        yield 'a comment' => ["Patch Set 3:\n\nPlease add a test.", ReviewEventEnum::COMMENTED, 'Please add a test.'];
        yield 'anything else' => ['Unset private', ReviewEventEnum::OTHER, 'Unset private'];
    }

    #[DataProvider('events')]
    public function testReadsTheEventOfTheLog(string $text, ReviewEventEnum $event, string $comment): void
    {
        $message = self::message($text);

        self::assertSame($event, $message->event);
        self::assertSame($comment, $message->comment);
    }

    public function testReadsTheVotesAndTheCommentOfAPerson(): void
    {
        $message = self::message("Patch Set 1: Verified-1\n\nThe tests fail: https://git.typo3.org/p/1");

        self::assertSame(ReviewEventEnum::COMMENTED, $message->event);
        self::assertSame([[ReviewLabelEnum::VERIFIED, ReviewVoteEnum::DISLIKED]], self::pairs($message->votes));
        self::assertSame('The tests fail: https://git.typo3.org/p/1', $message->comment);
    }

    public function testTheCiVotesWithANote(): void
    {
        $message = ReviewMessage::fromDocument([
            'author' => ['id' => 49454, 'name' => 'core-ci'],
            'message' => "Patch Set 1: Verified+1\n\nCore CI is happy: https://git.typo3.org/p/1",
        ]);

        self::assertTrue($message->isByCi());
        self::assertSame(ReviewEventEnum::VOTED, $message->event);
        self::assertSame('Core CI is happy: https://git.typo3.org/p/1', $message->comment);
    }

    public function testReadsSeveralVotesWithoutAComment(): void
    {
        $message = self::message('Patch Set 3: Code-Review+2 Verified+1');

        self::assertSame(ReviewEventEnum::VOTED, $message->event);
        self::assertSame(
            [
                [ReviewLabelEnum::CODE_REVIEW, ReviewVoteEnum::APPROVED],
                [ReviewLabelEnum::VERIFIED, ReviewVoteEnum::RECOMMENDED],
            ],
            self::pairs($message->votes),
        );
    }

    /**
     * @return iterable<string, array{string, list<array{ReviewLabelEnum, ReviewVoteEnum}>}>
     */
    public static function oldVotes(): iterable
    {
        yield 'approved and verified' => ['Verified; Looks good to me, approved', [
            [ReviewLabelEnum::VERIFIED, ReviewVoteEnum::RECOMMENDED],
            [ReviewLabelEnum::CODE_REVIEW, ReviewVoteEnum::APPROVED],
        ]];
        yield 'fails' => ["Fails; I would prefer that you didn't submit this", [
            [ReviewLabelEnum::VERIFIED, ReviewVoteEnum::DISLIKED],
            [ReviewLabelEnum::CODE_REVIEW, ReviewVoteEnum::DISLIKED],
        ]];
        yield 'by a team member' => ['Verified by team member', [
            [ReviewLabelEnum::VERIFIED, ReviewVoteEnum::APPROVED],
        ]];
    }

    /**
     * @param list<array{ReviewLabelEnum, ReviewVoteEnum}> $votes
     */
    #[DataProvider('oldVotes')]
    public function testReadsTheVotesGerritWroteUntil2013(string $line, array $votes): void
    {
        $message = self::message("Patch Set 1: {$line}");

        self::assertSame(ReviewEventEnum::VOTED, $message->event);
        self::assertSame($votes, self::pairs($message->votes));
    }

    public function testAnOldVoteWithACommentIsAComment(): void
    {
        $message = self::message("Patch Set 1: Looks good to me, approved\n\nNice work.");

        self::assertSame(ReviewEventEnum::COMMENTED, $message->event);
        self::assertSame('Nice work.', $message->comment);
        self::assertSame([[ReviewLabelEnum::CODE_REVIEW, ReviewVoteEnum::APPROVED]], self::pairs($message->votes));
    }

    public function testAVoteCanBeRemoved(): void
    {
        $votes = self::message('Patch Set 2: -Code-Review')->votes;

        self::assertSame([[ReviewLabelEnum::CODE_REVIEW, ReviewVoteEnum::NONE]], self::pairs($votes));
    }

    public function testReadsTheOutdatedVotesAndTheCommentsOnTheCode(): void
    {
        $message = self::message(
            "Uploaded patch set 2.\n\nOutdated Votes:\n* Verified+1 (copy condition: \"changekind:NO_CHANGE\")\n",
        );

        self::assertSame(ReviewEventEnum::UPLOADED, $message->event);
        $outdated = self::pairs($message->outdatedVotes);
        self::assertSame([[ReviewLabelEnum::VERIFIED, ReviewVoteEnum::RECOMMENDED]], $outdated);
        self::assertSame('', $message->comment);

        $message = self::message("Patch Set 4: Code-Review-1\n\n(3 comments)\n\nSee the inline comments.");
        self::assertSame(3, $message->inlineComments);
        self::assertSame('See the inline comments.', $message->comment);
    }

    /**
     * @return iterable<string, array{string, string, ReviewEventEnum, string}>
     */
    public static function taggedEvents(): iterable
    {
        $cherryPick = 'Patch Set 1: Cherry Picked from branch main.';
        yield 'a cherry-pick' => [$cherryPick, 'newPatchSet', ReviewEventEnum::UPLOADED, ''];
        yield 'a cherry-pick in progress' => [$cherryPick, 'newWipPatchSet', ReviewEventEnum::UPLOADED, ''];
        $rebase = 'Uploaded patch set 4: Patch Set 3 was rebased.';
        yield 'a rebase' => [$rebase, 'newPatchSet', ReviewEventEnum::REBASED, ''];
        $wip = "Set Work In Progress\n\nNot done yet.";
        yield 'work in progress' => [$wip, 'setWorkInProgress', ReviewEventEnum::WORK_IN_PROGRESS, 'Not done yet.'];
        yield 'abandoned' => ["Abandoned\n\nNot needed.", 'abandon', ReviewEventEnum::ABANDONED, 'Not needed.'];
        yield 'a comment' => ["Patch Set 2:\n\nLooks good.", 'other', ReviewEventEnum::COMMENTED, 'Looks good.'];
    }

    #[DataProvider('taggedEvents')]
    public function testTheTagOfGerritTellsTheEvent(
        string $text,
        string $tag,
        ReviewEventEnum $event,
        string $comment,
    ): void {
        $message = ReviewMessage::fromDocument([
            'message' => $text,
            'patch_set' => 1,
            'tag' => 'autogenerated:gerrit:' . $tag,
        ]);

        self::assertSame($event, $message->event);
        self::assertSame($comment, $message->comment);
    }

    /**
     * @return iterable<string, array{string, string|null, bool|null}>
     */
    public static function workInProgress(): iterable
    {
        yield 'a new patch set in progress' => ['Uploaded patch set 1.', 'newWipPatchSet', true];
        yield 'a new patch set ready' => ['Uploaded patch set 2.', 'newPatchSet', false];
        yield 'set in progress' => ['Set Work In Progress', 'setWorkInProgress', true];
        yield 'set ready' => ['Set Ready For Review', 'setReadyForReview', false];
        yield 'a comment that marks it ready' => ["Patch Set 2:\n\nThis change is ready for review.", null, false];
        yield 'a comment' => ["Patch Set 2:\n\nLooks good.", null, null];
        yield 'set private' => ['Set private', 'setPrivate', null];
    }

    #[DataProvider('workInProgress')]
    public function testTellsWhetherTheChangeIsInProgressAfterIt(string $text, ?string $tag, ?bool $expected): void
    {
        $message = ReviewMessage::fromDocument([
            'message' => $text,
            'patch_set' => 1,
            'tag' => null === $tag ? null : 'autogenerated:gerrit:' . $tag,
        ]);

        self::assertSame($expected, $message->workInProgress);
    }

    public function testTheLineThatMarksAChangeReadyIsNoComment(): void
    {
        $message = self::message("Patch Set 2:\n\nPlease merge.\n\nThis change is ready for review.");

        self::assertSame('Please merge.', $message->comment);
    }

    private static function message(string $text): ReviewMessage
    {
        return ReviewMessage::fromDocument(['message' => $text, 'patch_set' => 1]);
    }

    /**
     * @param list<array{label: ReviewLabelEnum, vote: ReviewVoteEnum}> $votes
     *
     * @return list<array{ReviewLabelEnum, ReviewVoteEnum}>
     */
    private static function pairs(array $votes): array
    {
        return array_map(static fn (array $vote): array => [$vote['label'], $vote['vote']], $votes);
    }
}
