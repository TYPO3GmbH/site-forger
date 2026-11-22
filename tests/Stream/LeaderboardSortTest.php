<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Stream;

use App\Enum\StreamSourceEnum;
use App\Stream\LeaderboardSort;
use PHPUnit\Framework\TestCase;

final class LeaderboardSortTest extends TestCase
{
    public function testAFigureOfTheOtherSourceSortsByAllEvents(): void
    {
        $reviews = LeaderboardSort::fromValue('reviews');

        self::assertSame('reviews', $reviews->forSource(StreamSourceEnum::GERRIT)->value());
        self::assertSame('reviews', $reviews->forSource(null)->value());
        self::assertNull($reviews->forSource(StreamSourceEnum::FORGE)->value());
    }

    public function testTheLinesSortOnlyWithGerrit(): void
    {
        $added = LeaderboardSort::fromValue(LeaderboardSort::ADDED);

        self::assertSame('added', $added->forSource(StreamSourceEnum::GERRIT)->value());
        self::assertNull($added->forSource(StreamSourceEnum::FORGE)->value());
    }
}
