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
use App\Enum\ActivityEnum;
use App\Enum\CommandRunStatusEnum;
use App\Enum\IssueMarkEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewLabelStateEnum;
use App\Enum\ReviewSizeEnum;
use App\Enum\ReviewStateEnum;
use App\Enum\ReviewStatusEnum;
use App\Enum\ReviewVoteEnum;
use App\Enum\StreamNewcomerStateEnum;
use App\Enum\SystemCheckEnum;
use PHPUnit\Framework\TestCase;

final class StatusEnumTest extends TestCase
{
    public function testIssueStatesLeaveOutTheForgeIdsTheProjectDoesNotUse(): void
    {
        self::assertNotContains(10, array_column(IssueStatusEnum::cases(), 'value'));
    }

    public function testIssueStatesHaveTheForgeNamesAsLabels(): void
    {
        self::assertSame('In Progress', IssueStatusEnum::IN_PROGRESS->label());
        self::assertSame(IssueStatusEnum::NEEDS_FEEDBACK, IssueStatusEnum::tryFromLabel('Needs Feedback'));
        self::assertNull(IssueStatusEnum::tryFromLabel('in progress'));

        $labels = array_map(static fn (IssueStatusEnum $state): string => $state->label(), IssueStatusEnum::cases());
        self::assertSame($labels, array_values(array_unique($labels)));
    }

    public function testClosedIssueStates(): void
    {
        self::assertSame(['Closed', 'Rejected', 'Resolved'], IssueStatusEnum::closedLabels());
        self::assertTrue(IssueStatusEnum::RESOLVED->isClosed());
        self::assertFalse(IssueStatusEnum::UNDER_REVIEW->isClosed());
        self::assertFalse(IssueStatusEnum::POSTPONED->isClosed());
    }

    public function testReviewStatesHaveLabels(): void
    {
        self::assertSame('Open', ReviewStatusEnum::NEW->label());
        self::assertSame(ReviewStatusEnum::ABANDONED, ReviewStatusEnum::tryFromLabel('Abandoned'));
    }

    public function testClosedReviewStates(): void
    {
        self::assertSame(['MERGED', 'ABANDONED'], ReviewStatusEnum::closedValues());
        self::assertTrue(ReviewStatusEnum::ABANDONED->isClosed());
        self::assertFalse(ReviewStatusEnum::NEW->isClosed());
    }

    public function testReviewStateOfAChangeKeepsWorkInProgressApartFromTheStatus(): void
    {
        self::assertSame(ReviewStateEnum::READY, Change::fromDocument(['reviewstatus' => 'NEW', 'work_in_progress' => false])->state());
        self::assertSame(ReviewStateEnum::WORK_IN_PROGRESS, Change::fromDocument(['reviewstatus' => 'NEW', 'work_in_progress' => true])->state());
        self::assertSame(ReviewStateEnum::MERGED, Change::fromDocument(['reviewstatus' => 'MERGED'])->state());
        // Abandoned work in progress is abandoned
        self::assertSame(ReviewStateEnum::ABANDONED, Change::fromDocument(['reviewstatus' => 'ABANDONED', 'work_in_progress' => true])->state());
        self::assertSame(ReviewStatusEnum::NEW, ReviewStateEnum::WORK_IN_PROGRESS->status());
        self::assertTrue(ReviewStateEnum::ABANDONED->isClosed());
        self::assertFalse(ReviewStateEnum::WORK_IN_PROGRESS->isClosed());
    }

    public function testEveryStateHasItsOwnSeverity(): void
    {
        $issueSeverities = array_map(static fn (IssueStatusEnum $state): string => $state->severity(), IssueStatusEnum::cases());
        $reviewSeverities = array_map(static fn (ReviewStateEnum $state): string => $state->severity(), ReviewStateEnum::cases());

        self::assertSame('in-progress', IssueStatusEnum::IN_PROGRESS->severity());
        self::assertSame('wip', ReviewStateEnum::WORK_IN_PROGRESS->severity());
        self::assertSame($issueSeverities, array_values(array_unique($issueSeverities)));
        self::assertSame($reviewSeverities, array_values(array_unique($reviewSeverities)));

        $runSeverities = array_map(static fn (CommandRunStatusEnum $state): string => $state->severity(), CommandRunStatusEnum::cases());
        self::assertSame($runSeverities, array_values(array_unique($runSeverities)));

        // Activity stages are shown next to the review state, their pills must not look alike
        $activitySeverities = array_map(static fn (ActivityEnum $stage): string => $stage->severity(), ActivityEnum::cases());
        self::assertSame($activitySeverities, array_values(array_unique($activitySeverities)));
        self::assertSame([], array_intersect($activitySeverities, $reviewSeverities));
    }

    public function testEverySeverityHasAStyle(): void
    {
        $styles = (string) file_get_contents(__DIR__ . '/../../assets/css/module/_status.scss');
        $states = [
            ...IssueStatusEnum::cases(),
            ...ReviewStateEnum::cases(),
            ...ActivityEnum::cases(),
            ...IssueMarkEnum::cases(),
            ...CommandRunStatusEnum::cases(),
            ...SystemCheckEnum::cases(),
            ...ReviewVoteEnum::cases(),
            ...ReviewSizeEnum::cases(),
            ...ReviewLabelStateEnum::cases(),
            ...StreamNewcomerStateEnum::cases(),
        ];
        foreach ($states as $state) {
            self::assertMatchesRegularExpression('/\\.status-pill-' . preg_quote($state->severity(), '/') . '[\\s,{]/', $styles, $state->name . ' has no status pill style');
        }
    }
}
