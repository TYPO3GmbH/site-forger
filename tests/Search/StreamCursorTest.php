<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Document\StreamEvent;
use App\Enum\StreamKindEnum;
use App\Enum\StreamSourceEnum;
use App\Search\StreamCursor;
use PHPUnit\Framework\TestCase;

final class StreamCursorTest extends TestCase
{
    public function testTheCursorAfterAnEventIsItsTimeInUtcAndItsId(): void
    {
        $cursor = StreamCursor::after(self::event('2025-11-22 13:20:00'));

        self::assertNotNull($cursor);
        self::assertSame('1763817600000-gerrit:91234:7', $cursor->value());
        self::assertSame([1763817600000, 'gerrit:91234:7'], $cursor->searchAfter());
    }

    public function testTheTextOfACursorGivesItBack(): void
    {
        $cursor = StreamCursor::fromValue('1763817600000-forge:111431-2');

        self::assertNotNull($cursor);
        self::assertSame([1763817600000, 'forge:111431-2'], $cursor->searchAfter());
    }

    public function testAnotherTextIsNoCursor(): void
    {
        self::assertNull(StreamCursor::fromValue('25'));
        self::assertNull(StreamCursor::fromValue('soon-forge:1'));
        self::assertNull(StreamCursor::after(self::event('not a date')));
    }

    private static function event(string $utc): StreamEvent
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));

        return new StreamEvent(
            'gerrit:91234:7',
            StreamSourceEnum::GERRIT,
            StreamKindEnum::VOTED,
            91234,
            'A change',
            false === $date ? $utc : $date->format('Y/m/d H:i:s'),
        );
    }
}
