<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Enum;

use App\Document\Change;
use App\Enum\ReviewVoteEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewVoteEnumTest extends TestCase
{
    public function testVotesAreTheGerritValues(): void
    {
        self::assertSame(ReviewVoteEnum::APPROVED, ReviewVoteEnum::from(2));
        self::assertSame(ReviewVoteEnum::REJECTED, ReviewVoteEnum::from(-2));
        self::assertSame('+2', ReviewVoteEnum::APPROVED->label());
        self::assertSame('-1', ReviewVoteEnum::DISLIKED->label());
        self::assertSame('0', ReviewVoteEnum::NONE->label());
    }

    public function testOnlyTheVotesOfMergersAreStrong(): void
    {
        self::assertSame(
            [ReviewVoteEnum::REJECTED, ReviewVoteEnum::APPROVED],
            array_values(array_filter(ReviewVoteEnum::cases(), static fn (ReviewVoteEnum $vote): bool => $vote->isStrong()))
        );
    }

    /**
     * @return iterable<string, array{array<string, int>, ReviewVoteEnum}>
     */
    public static function changes(): iterable
    {
        yield 'a +2 of a merger' => [['lowest_review_vote' => 0, 'highest_review_vote' => 2, 'positive_reviews' => 2], ReviewVoteEnum::APPROVED];
        yield 'two +1 are no +2' => [['lowest_review_vote' => 0, 'highest_review_vote' => 1, 'positive_reviews' => 2], ReviewVoteEnum::RECOMMENDED];
        yield 'a -1 outweighs a +2' => [['lowest_review_vote' => -1, 'highest_review_vote' => 2], ReviewVoteEnum::DISLIKED];
        yield 'a -2 of a merger' => [['lowest_review_vote' => -2, 'highest_review_vote' => 1], ReviewVoteEnum::REJECTED];
        yield 'no votes' => [['lowest_review_vote' => 0, 'highest_review_vote' => 0], ReviewVoteEnum::NONE];
        // Imported before the single votes: the sums tell a vote, but not whether a merger gave it
        yield 'old change with positive votes' => [['positive_reviews' => 4, 'negative_reviews' => 0], ReviewVoteEnum::RECOMMENDED];
        yield 'old change with negative votes' => [['positive_reviews' => 2, 'negative_reviews' => 2], ReviewVoteEnum::DISLIKED];
        yield 'old change without votes' => [[], ReviewVoteEnum::NONE];
    }

    /**
     * @param array<string, int> $change
     */
    #[DataProvider('changes')]
    public function testTheVoteThatCounts(array $change, ReviewVoteEnum $expected): void
    {
        self::assertSame($expected, Change::fromDocument($change)->vote('review'));
    }

    public function testTheLabelsAreReadApart(): void
    {
        $change = ['lowest_review_vote' => 0, 'highest_review_vote' => 2, 'lowest_verification_vote' => -1, 'highest_verification_vote' => 0];

        self::assertSame(ReviewVoteEnum::APPROVED, Change::fromDocument($change)->vote('review'));
        self::assertSame(ReviewVoteEnum::DISLIKED, Change::fromDocument($change)->vote('verification'));
    }
}
