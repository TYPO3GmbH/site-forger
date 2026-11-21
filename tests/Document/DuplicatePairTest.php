<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Document;

use App\Document\DuplicateCheck;
use App\Document\DuplicatePair;
use App\Document\Issue;
use App\Duplicates\DuplicateHit;
use PHPUnit\Framework\TestCase;

final class DuplicatePairTest extends TestCase
{
    public function testStoresThePairOnceTheLowerNumberFirst(): void
    {
        $checkedOn = '2026/11/21 10:00:00';
        $fromHigher = DuplicatePair::of(91100, DuplicateHit::checked(new Issue(80001, 'Older'), 60, 30), $checkedOn);
        $fromLower = DuplicatePair::of(80001, DuplicateHit::checked(new Issue(91100, 'Newer'), 30, 60), $checkedOn);

        self::assertEquals($fromHigher, $fromLower);
        self::assertSame('pair-80001-91100', $fromHigher->id());
        // Seen from 91100, the older issue reaches 60 %
        self::assertSame([45, 30, 60], [$fromHigher->similarity, $fromHigher->fromLower, $fromHigher->fromHigher]);
    }

    public function testReadsWhatItWrites(): void
    {
        $pair = new DuplicatePair(80001, 91100, 45, 30, 60, '2026/11/21 10:00:00');
        $check = new DuplicateCheck(91100, '2026/11/20 10:00:00', '2026/11/21 10:00:00');

        self::assertEquals($pair, DuplicatePair::fromDocument($pair->toDocument()));
        self::assertEquals($check, DuplicateCheck::fromDocument($check->toDocument()));
        self::assertSame('check-91100', $check->id());
    }
}
