<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\ReviewSortEnum;
use App\Search\ReviewSizeQuery;
use App\Search\ReviewSortQuery;
use PHPUnit\Framework\TestCase;

final class ReviewSortQueryTest extends TestCase
{
    public function testTheRecentlyUpdatedComeFirstAsTheListsDidBefore(): void
    {
        self::assertSame(
            [['updated_on' => 'desc'], ['insertions' => 'asc'], ['deletions' => 'asc'], ['patchsets' => 'desc']],
            ReviewSortQuery::sort(ReviewSortEnum::UPDATED)
        );
        self::assertSame(['updated_on' => 'asc'], ReviewSortQuery::sort(ReviewSortEnum::LEAST_UPDATED)[0]);
    }

    public function testSmallestAddsTheInsertionsAndDeletions(): void
    {
        $script = ReviewSortQuery::sort(ReviewSortEnum::SMALLEST)[0]['_script'];

        self::assertSame('asc', $script['order']);
        self::assertSame(ReviewSizeQuery::LINES_SCRIPT, $script['script']['source']);
    }
}
