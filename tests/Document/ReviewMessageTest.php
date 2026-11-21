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
