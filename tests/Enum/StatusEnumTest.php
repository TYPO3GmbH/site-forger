<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Enum;

use App\Enum\CommandRunStatusEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStateEnum;
use App\Enum\ReviewStatusEnum;
use PHPUnit\Framework\TestCase;

final class StatusEnumTest extends TestCase
{
    public function testIssueStatesAreTheForgeIds(): void
    {
        self::assertSame(IssueStatusEnum::UNDER_REVIEW, IssueStatusEnum::from(8));
        self::assertSame(IssueStatusEnum::IN_PROGRESS, IssueStatusEnum::from(11));
        self::assertSame(IssueStatusEnum::POSTPONED, IssueStatusEnum::tryFrom(9));
        self::assertNull(IssueStatusEnum::tryFrom(10));
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

    public function testReviewStatesAreTheGerritValuesWithLabels(): void
    {
        self::assertSame(ReviewStatusEnum::MERGED, ReviewStatusEnum::from('MERGED'));
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
        self::assertSame(ReviewStateEnum::READY, ReviewStateEnum::fromChange(['reviewstatus' => 'NEW', 'work_in_progress' => false]));
        self::assertSame(ReviewStateEnum::WORK_IN_PROGRESS, ReviewStateEnum::fromChange(['reviewstatus' => 'NEW', 'work_in_progress' => true]));
        self::assertSame(ReviewStateEnum::MERGED, ReviewStateEnum::fromChange(['reviewstatus' => 'MERGED']));
        // Abandoned work in progress is abandoned
        self::assertSame(ReviewStateEnum::ABANDONED, ReviewStateEnum::fromChange(['reviewstatus' => 'ABANDONED', 'work_in_progress' => true]));
        self::assertSame(ReviewStatusEnum::NEW, ReviewStateEnum::WORK_IN_PROGRESS->status());
        self::assertTrue(ReviewStateEnum::ABANDONED->isClosed());
        self::assertFalse(ReviewStateEnum::WORK_IN_PROGRESS->isClosed());
    }

    public function testReviewStateQueries(): void
    {
        self::assertSame(
            ['bool' => ['filter' => [['term' => ['reviewstatus' => 'NEW']]], 'must_not' => [['term' => ['work_in_progress' => true]]]]],
            ReviewStateEnum::READY->query()
        );
        self::assertSame(['term' => ['reviewstatus' => 'MERGED']], ReviewStateEnum::MERGED->query());
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
    }

    public function testEverySeverityHasAStyle(): void
    {
        $styles = (string) file_get_contents(__DIR__ . '/../../assets/css/module/_status.scss');
        foreach ([...IssueStatusEnum::cases(), ...ReviewStateEnum::cases(), ...CommandRunStatusEnum::cases()] as $state) {
            self::assertMatchesRegularExpression('/\\.status-pill-' . preg_quote($state->severity(), '/') . '[\\s,{]/', $styles, $state->name . ' has no status pill style');
        }
    }
}
