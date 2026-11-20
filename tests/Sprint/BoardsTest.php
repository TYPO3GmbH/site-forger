<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Sprint;

use App\Document\Change;
use App\Enum\IssueStatusEnum;
use App\Sprint\BoardColumnEnum;
use App\Sprint\ReviewBoards;
use App\Sprint\SprintBoards;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoardsTest extends TestCase
{
    /**
     * @return iterable<string, array{?IssueStatusEnum, BoardColumnEnum}>
     */
    public static function statuses(): iterable
    {
        yield 'new' => [IssueStatusEnum::NEW, BoardColumnEnum::OPEN];
        yield 'postponed' => [IssueStatusEnum::POSTPONED, BoardColumnEnum::OPEN];
        yield 'in progress' => [IssueStatusEnum::IN_PROGRESS, BoardColumnEnum::WIP];
        yield 'under review' => [IssueStatusEnum::UNDER_REVIEW, BoardColumnEnum::REVIEW];
        yield 'on hold' => [IssueStatusEnum::ON_HOLD, BoardColumnEnum::BLOCKED];
        yield 'needs feedback' => [IssueStatusEnum::NEEDS_FEEDBACK, BoardColumnEnum::BLOCKED];
        yield 'resolved' => [IssueStatusEnum::RESOLVED, BoardColumnEnum::DONE];
        yield 'rejected' => [IssueStatusEnum::REJECTED, BoardColumnEnum::DONE];
        yield 'unknown' => [null, BoardColumnEnum::OPEN];
    }

    #[DataProvider('statuses')]
    public function testEachStatusHasItsColumn(?IssueStatusEnum $status, BoardColumnEnum $column): void
    {
        self::assertSame($column, BoardColumnEnum::ofStatus($status));
    }

    public function testTheColumnsAreInTheOrderOfTheBoard(): void
    {
        self::assertSame(['BLOCKED', 'Open', 'WIP', 'Review', 'Done'], array_keys(BoardColumnEnum::each([])));
    }

    public function testTheProgressHasAShareForEachColumn(): void
    {
        $progress = SprintBoards::progress(['Open' => 3, 'WIP' => 1, 'Done' => 0]);

        self::assertSame(4, $progress['total']);
        self::assertEqualsWithDelta(75.0, $progress['sections']['open']['value'], 0.001);
        self::assertSame('wip active progress-bar-striped', $progress['sections']['wip']['classes']);
        self::assertEqualsWithDelta(0.0, $progress['sections']['done']['value'], 0.001);
    }

    public function testAnEmptyBoardHasNoProgress(): void
    {
        self::assertSame(0, SprintBoards::progress(BoardColumnEnum::each(0))['total']);
    }

    /**
     * @return iterable<string, array{array<string, int|string>, string}>
     */
    public static function changes(): iterable
    {
        $votes = static fn (int $pr, int $pv, int $nr = 0, int $nv = 0, string $mergeable = 'yes'): array => [
            'mergeable' => $mergeable, 'positive_reviews' => $pr, 'positive_verifications' => $pv, 'negative_reviews' => $nr, 'negative_verifications' => $nv,
        ];
        yield 'no votes' => [$votes(0, 0), 'missesBoth'];
        yield 'reviewed only' => [$votes(1, 0), 'missesTesting'];
        yield 'verified only' => [$votes(0, 1), 'missesCodeReview'];
        yield 'both' => [$votes(2, 1), 'hasPlusOne'];
        yield 'a negative review' => [$votes(2, 1, 1), 'blocked'];
        yield 'a negative verification' => [$votes(0, 0, 0, 1), 'blocked'];
        yield 'a merge conflict' => [$votes(2, 1, 0, 0, 'no'), 'mergeConflict'];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('changes')]
    public function testEachChangeHasTheGroupOfTheVotesItNeeds(array $change, string $group): void
    {
        self::assertSame($group, ReviewBoards::group(Change::fromDocument($change)));
    }
}
