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
use App\Enum\ReviewStatusEnum;
use PHPUnit\Framework\TestCase;

final class StatusEnumTest extends TestCase
{
    public function testClosedIssueStates(): void
    {
        self::assertSame(['Closed', 'Rejected', 'Resolved'], IssueStatusEnum::closedValues());
        self::assertTrue(IssueStatusEnum::RESOLVED->isClosed());
        self::assertFalse(IssueStatusEnum::UNDER_REVIEW->isClosed());
    }

    public function testClosedReviewStates(): void
    {
        self::assertSame(['MERGED', 'ABANDONED'], ReviewStatusEnum::closedValues());
        self::assertTrue(ReviewStatusEnum::ABANDONED->isClosed());
        self::assertFalse(ReviewStatusEnum::NEW->isClosed());
    }
}
