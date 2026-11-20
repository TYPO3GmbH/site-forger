<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\IssueSortEnum;
use App\Search\IssueSortQuery;
use PHPUnit\Framework\TestCase;

final class IssueSortQueryTest extends TestCase
{
    public function testDateOrders(): void
    {
        self::assertSame([['created_on' => 'desc']], IssueSortQuery::sort(IssueSortEnum::NEWEST));
        self::assertSame([['created_on' => 'asc']], IssueSortQuery::sort(IssueSortEnum::OLDEST));
        self::assertSame([['updated_on' => 'desc']], IssueSortQuery::sort(IssueSortEnum::UPDATED));
        self::assertSame([['updated_on' => 'asc']], IssueSortQuery::sort(IssueSortEnum::LEAST_UPDATED));
    }

    public function testPriorityPutsMustHaveFirstAndIssuesWithoutPriorityLast(): void
    {
        self::assertSame(
            [
                ['priority.id' => ['order' => 'asc', 'missing' => '_last', 'unmapped_type' => 'long']],
                ['updated_on' => 'desc'],
            ],
            IssueSortQuery::sort(IssueSortEnum::PRIORITY)
        );
    }
}
