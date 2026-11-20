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
use App\Enum\ReviewSizeEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewSizeEnumTest extends TestCase
{
    /**
     * @return iterable<string, array{int, ReviewSizeEnum}>
     */
    public static function lines(): iterable
    {
        yield 'nothing' => [0, ReviewSizeEnum::EASY];
        yield 'easy at most' => [50, ReviewSizeEnum::EASY];
        yield 'normal at least' => [51, ReviewSizeEnum::NORMAL];
        yield 'normal at most' => [250, ReviewSizeEnum::NORMAL];
        yield 'hard at least' => [251, ReviewSizeEnum::HARD];
        yield 'hard at most' => [1000, ReviewSizeEnum::HARD];
        yield 'expert at least' => [1001, ReviewSizeEnum::EXPERT];
        yield 'expert at most' => [2500, ReviewSizeEnum::EXPERT];
        yield 'nightmare at least' => [2501, ReviewSizeEnum::NIGHTMARE];
        yield 'nightmare at most' => [10000, ReviewSizeEnum::NIGHTMARE];
        yield 'impossible' => [10001, ReviewSizeEnum::IMPOSSIBLE];
    }

    #[DataProvider('lines')]
    public function testTheLinesGiveTheSize(int $lines, ReviewSizeEnum $size): void
    {
        self::assertSame($size, ReviewSizeEnum::fromLines($lines));
    }

    public function testTheAddedAndRemovedLinesCountTogether(): void
    {
        self::assertSame(ReviewSizeEnum::NORMAL, Change::fromDocument(['insertions' => 30, 'deletions' => 30])->size());
    }

    public function testChangesImportedWithoutLinesHaveNoSize(): void
    {
        self::assertNull(Change::fromDocument(['insertions' => 30])->size());
        self::assertNull(Change::fromDocument([])->size());
    }

    public function testTheSizesFollowEachOtherWithoutAGap(): void
    {
        self::assertSame([0, 51, 251, 1001, 2501, 10001], array_map(static fn (ReviewSizeEnum $size): int => $size->minimum(), ReviewSizeEnum::cases()));
        foreach (ReviewSizeEnum::cases() as $size) {
            self::assertSame($size, ReviewSizeEnum::fromLines($size->minimum()));
        }
    }

    public function testDescriptions(): void
    {
        self::assertSame('up to 50 lines changed', ReviewSizeEnum::EASY->description());
        self::assertSame('up to 10000 lines changed', ReviewSizeEnum::NIGHTMARE->description());
        self::assertSame('more than 10000 lines changed', ReviewSizeEnum::IMPOSSIBLE->description());
    }

    public function testLabelsSelectTheSize(): void
    {
        self::assertSame(ReviewSizeEnum::NIGHTMARE, ReviewSizeEnum::tryFromLabel('Nightmare'));
        self::assertSame(ReviewSizeEnum::IMPOSSIBLE, ReviewSizeEnum::tryFromLabel('Impossible'));
        self::assertNull(ReviewSizeEnum::tryFromLabel('nightmare'));
    }
}
