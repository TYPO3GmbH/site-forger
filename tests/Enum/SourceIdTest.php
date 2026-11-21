<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Enum;

use App\Enum\IssueStatusEnum;
use App\Enum\PriorityEnum;
use App\Enum\ReviewLabelEnum;
use App\Enum\ReviewStatusEnum;
use App\Enum\ReviewVoteEnum;
use App\Enum\TrackerEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The value of an enum is the id of the source system, see AGENTS.md: a Redmine id or a Gerrit value.
 */
final class SourceIdTest extends TestCase
{
    /**
     * @return iterable<string, array{\BackedEnum, int|string}>
     */
    public static function ids(): iterable
    {
        yield 'tracker' => [TrackerEnum::EPIC, 10];
        yield 'priority' => [PriorityEnum::MUST_HAVE, 3];
        yield 'issue status under review' => [IssueStatusEnum::UNDER_REVIEW, 8];
        yield 'issue status in progress' => [IssueStatusEnum::IN_PROGRESS, 11];
        yield 'issue status postponed' => [IssueStatusEnum::POSTPONED, 9];
        yield 'review status' => [ReviewStatusEnum::MERGED, 'MERGED'];
        yield 'vote approved' => [ReviewVoteEnum::APPROVED, 2];
        yield 'vote rejected' => [ReviewVoteEnum::REJECTED, -2];
        yield 'label code review' => [ReviewLabelEnum::CODE_REVIEW, 'Code-Review'];
        yield 'label verified' => [ReviewLabelEnum::VERIFIED, 'Verified'];
    }

    #[DataProvider('ids')]
    public function testTheValueIsTheIdOfTheSourceSystem(\BackedEnum $case, int|string $id): void
    {
        self::assertSame($case, $case::from($id));
    }
}
