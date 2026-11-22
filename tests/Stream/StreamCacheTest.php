<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Stream;

use App\Enum\StreamRangeEnum;
use App\Search\StreamFilter;
use App\Search\StreamWindow;
use App\Stream\StreamCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class StreamCacheTest extends TestCase
{
    public function testTheSameQuestionIsComputedOnce(): void
    {
        $cache = new StreamCache(new ArrayAdapter());
        $calls = 0;
        $compute = static function () use (&$calls): int {
            return ++$calls;
        };

        $first = $cache->get('counts', [new StreamFilter(), self::week()], $compute);
        $second = $cache->get('counts', [new StreamFilter(), self::week()], $compute);

        self::assertSame(1, $first);
        self::assertSame(1, $second);
        self::assertSame(1, $calls);
    }

    public function testAnotherFilterOrNameIsComputedAgain(): void
    {
        $cache = new StreamCache(new ArrayAdapter());
        $calls = 0;
        $compute = static function () use (&$calls): int {
            return ++$calls;
        };

        $cache->get('counts', [new StreamFilter(), self::week()], $compute);
        $cache->get('counts', [new StreamFilter(bots: true), self::week()], $compute);
        $cache->get('insights', [new StreamFilter(), self::week()], $compute);

        self::assertSame(3, $calls);
    }

    private static function week(): StreamWindow
    {
        $now = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('UTC'));

        return StreamWindow::ofRange(StreamRangeEnum::WEEK, $now);
    }
}
