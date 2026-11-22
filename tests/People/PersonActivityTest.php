<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\People;

use App\People\PersonActivity;
use PHPUnit\Framework\TestCase;

final class PersonActivityTest extends TestCase
{
    public function testTheSparklineIsAsHighAsTheBusiestWeek(): void
    {
        $activity = new PersonActivity(3, 1, 2, 5, [0, 4, 2]);

        self::assertSame(6, $activity->events());
        self::assertSame(
            ['line' => '0.00,28.00 50.00,2.00 100.00,15.00', 'area' => '0,30 0.00,28.00 50.00,2.00 100.00,15.00 100,30'],
            $activity->sparkline(),
        );
    }

    public function testAPersonWithoutEventsHasAFlatLine(): void
    {
        $activity = PersonActivity::none(3);

        self::assertSame(0, $activity->events());
        self::assertSame('0.00,28.00 50.00,28.00 100.00,28.00', $activity->sparkline()['line']);
        self::assertSame('', new PersonActivity(0, 0, 0, 0, [5])->sparkline()['line']);
    }
}
