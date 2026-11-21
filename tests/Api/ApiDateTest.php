<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Api;

use App\Api\ApiDate;
use PHPUnit\Framework\TestCase;

final class ApiDateTest extends TestCase
{
    public function testWritesADateOfTheIndexInIso8601(): void
    {
        self::assertSame('2026-11-20T23:40:03Z', ApiDate::fromIndex('2026/11/20 23:40:03'));
    }

    public function testHasNoDateWithoutAValidOne(): void
    {
        self::assertNull(ApiDate::fromIndex(null));
        self::assertNull(ApiDate::fromIndex(''));
        self::assertNull(ApiDate::fromIndex('yesterday'));
    }
}
