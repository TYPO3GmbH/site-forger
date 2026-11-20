<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Enum;

use App\Enum\ReviewSizeEnum;
use App\Enum\ReviewSortEnum;
use PHPUnit\Framework\TestCase;

final class ReviewSortEnumTest extends TestCase
{
    public function testRelevanceIsOfferedForSearchWordsOnly(): void
    {
        self::assertContains(ReviewSortEnum::RELEVANCE, ReviewSortEnum::options(true));
        self::assertNotContains(ReviewSortEnum::RELEVANCE, ReviewSortEnum::options(false));
        self::assertCount(count(ReviewSortEnum::cases()) - 1, ReviewSortEnum::options(false));
    }

    public function testDefaults(): void
    {
        self::assertSame(ReviewSortEnum::RELEVANCE, ReviewSortEnum::fromRequest('', true));
        self::assertSame(ReviewSortEnum::UPDATED, ReviewSortEnum::fromRequest('', false));
        self::assertSame(ReviewSortEnum::UPDATED, ReviewSortEnum::fromRequest('nonsense', false));
        self::assertSame(ReviewSortEnum::UPDATED, ReviewSortEnum::fromRequest('relevance', false));
    }

    public function testAListHasADefaultOfItsOwn(): void
    {
        self::assertSame(ReviewSortEnum::LEAST_UPDATED, ReviewSortEnum::fromRequest('', false, ReviewSortEnum::LEAST_UPDATED));
        // Search words rank the changes in every list
        self::assertSame(ReviewSortEnum::RELEVANCE, ReviewSortEnum::fromRequest('', true, ReviewSortEnum::LEAST_UPDATED));
        self::assertSame(ReviewSortEnum::NEWEST, ReviewSortEnum::fromRequest('newest', false, ReviewSortEnum::LEAST_UPDATED));
    }

    public function testLabelsAreUnique(): void
    {
        $labels = array_map(static fn (ReviewSortEnum $sort): string => $sort->label(), ReviewSortEnum::cases());
        self::assertSame($labels, array_values(array_unique($labels)));
    }

    public function testTheRecentlyUpdatedComeFirstAsTheListsDidBefore(): void
    {
        self::assertSame(
            [['updated_on' => 'desc'], ['insertions' => 'asc'], ['deletions' => 'asc'], ['patchsets' => 'desc']],
            ReviewSortEnum::UPDATED->sort()
        );
        self::assertSame(['updated_on' => 'asc'], ReviewSortEnum::LEAST_UPDATED->sort()[0]);
    }

    public function testSmallestAddsTheInsertionsAndDeletions(): void
    {
        $script = ReviewSortEnum::SMALLEST->sort()[0]['_script'];

        self::assertSame('asc', $script['order']);
        self::assertSame(ReviewSizeEnum::LINES_SCRIPT, $script['script']['source']);
    }
}
