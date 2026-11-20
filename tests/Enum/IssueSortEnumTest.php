<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Enum;

use App\Enum\IssueSortEnum;
use PHPUnit\Framework\TestCase;

final class IssueSortEnumTest extends TestCase
{
    public function testRelevanceIsOfferedForSearchWordsOnly(): void
    {
        self::assertContains(IssueSortEnum::RELEVANCE, IssueSortEnum::options(true));
        self::assertNotContains(IssueSortEnum::RELEVANCE, IssueSortEnum::options(false));
        self::assertCount(count(IssueSortEnum::cases()) - 1, IssueSortEnum::options(false));
    }

    public function testDefaults(): void
    {
        self::assertSame(IssueSortEnum::RELEVANCE, IssueSortEnum::fromRequest('', true));
        self::assertSame(IssueSortEnum::UPDATED, IssueSortEnum::fromRequest('', false));
        self::assertSame(IssueSortEnum::UPDATED, IssueSortEnum::fromRequest('nonsense', false));
        // Without search words relevance would be the order of the index
        self::assertSame(IssueSortEnum::UPDATED, IssueSortEnum::fromRequest('relevance', false));
    }

    public function testTheValueOfTheUrlSelectsTheOrder(): void
    {
        self::assertSame(IssueSortEnum::NEWEST, IssueSortEnum::fromRequest('newest', true));
        self::assertSame(IssueSortEnum::LEAST_UPDATED, IssueSortEnum::fromRequest('least-updated', false));
        // A list with an order of its own, search words still rank by relevance
        self::assertSame(IssueSortEnum::LEAST_UPDATED, IssueSortEnum::fromRequest('nonsense', false, IssueSortEnum::LEAST_UPDATED));
        self::assertSame(IssueSortEnum::NEWEST, IssueSortEnum::fromRequest('newest', false, IssueSortEnum::LEAST_UPDATED));
        self::assertSame(IssueSortEnum::RELEVANCE, IssueSortEnum::default(true, IssueSortEnum::LEAST_UPDATED));
    }

    public function testLabelsAreUnique(): void
    {
        $labels = array_map(static fn (IssueSortEnum $sort): string => $sort->label(), IssueSortEnum::cases());
        self::assertSame($labels, array_values(array_unique($labels)));
    }

    public function testDateOrders(): void
    {
        self::assertSame([['created_on' => 'desc']], IssueSortEnum::NEWEST->sort());
        self::assertSame([['created_on' => 'asc']], IssueSortEnum::OLDEST->sort());
        self::assertSame([['updated_on' => 'desc']], IssueSortEnum::UPDATED->sort());
        self::assertSame([['updated_on' => 'asc']], IssueSortEnum::LEAST_UPDATED->sort());
    }

    public function testPriorityPutsMustHaveFirstAndIssuesWithoutPriorityLast(): void
    {
        self::assertSame(
            [
                ['priority.id' => ['order' => 'asc', 'missing' => '_last', 'unmapped_type' => 'long']],
                ['updated_on' => 'desc'],
            ],
            IssueSortEnum::PRIORITY->sort()
        );
    }
}
