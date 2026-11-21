<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Duplicates;

use App\Document\DuplicateCheck;
use App\Duplicates\DuplicatesDue;
use PHPUnit\Framework\TestCase;

final class DuplicatesDueTest extends TestCase
{
    private const string STALE_BEFORE = '2026/11/14 00:00:00';

    public function testChecksChangedAndNewIssuesFirstThenTheOldestChecks(): void
    {
        $due = DuplicatesDue::of(
            [
                1 => '2026/11/20 10:00:00', // as checked
                2 => '2026/11/20 12:00:00', // changed since
                3 => '2026/11/20 11:00:00', // never checked
                4 => '2026/11/01 09:00:00', // checked long ago
                5 => '2026/10/01 09:00:00', // checked even longer ago
            ],
            [
                1 => new DuplicateCheck(1, '2026/11/20 10:00:00', '2026/11/20 10:05:00'),
                2 => new DuplicateCheck(2, '2026/11/20 09:00:00', '2026/11/20 09:05:00'),
                4 => new DuplicateCheck(4, '2026/11/01 09:00:00', '2026/11/10 00:00:00'),
                5 => new DuplicateCheck(5, '2026/10/01 09:00:00', '2026/11/02 00:00:00'),
            ],
            self::STALE_BEFORE,
            10,
        );

        self::assertSame([2, 3, 5, 4], $due->check);
        self::assertSame(2, $due->pending);
    }

    public function testRemovesTheIssuesNoLongerOpen(): void
    {
        $due = DuplicatesDue::of(
            [1 => '2026/11/20 10:00:00'],
            [
                1 => new DuplicateCheck(1, '2026/11/20 10:00:00', '2026/11/20 10:05:00'),
                9 => new DuplicateCheck(9, '2026/11/19 10:00:00', '2026/11/19 10:05:00'),
            ],
            self::STALE_BEFORE,
            10,
        );

        self::assertSame([], $due->check);
        self::assertSame([9], $due->remove);
    }

    public function testChecksNoMoreThanTheLimit(): void
    {
        $due = DuplicatesDue::of(
            [1 => '2026/11/20 10:00:00', 2 => '2026/11/20 11:00:00', 3 => '2026/11/20 12:00:00'],
            [],
            self::STALE_BEFORE,
            2,
        );

        self::assertSame([3, 2], $due->check);
        self::assertSame(3, $due->pending);
    }
}
