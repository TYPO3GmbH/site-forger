<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Stream;

use App\Enum\StreamCrewEnum;
use App\Stream\StreamCrew;
use PHPUnit\Framework\TestCase;

final class StreamCrewTest extends TestCase
{
    public function testTheCarriersAreTheFewestPeopleWhoDidHalfOfTheWork(): void
    {
        $crew = new StreamCrew(StreamCrewEnum::MERGING, [40, 30, 20, 10], [25, 25, 25, 25]);

        self::assertSame(4, $crew->people());
        self::assertSame(2, $crew->carriers());
        self::assertSame(2, $crew->previousCarriers());
        self::assertSame([40.0, 30.0], $crew->carrierShares());
    }

    public function testOnePersonWithHalfOfTheWorkCarriesItAlone(): void
    {
        $crew = new StreamCrew(StreamCrewEnum::VOTING, [50, 30, 20], []);

        self::assertSame(1, $crew->carriers());
        self::assertSame([50.0], $crew->carrierShares());
    }

    public function testNobodyCarriesWithoutWork(): void
    {
        $crew = new StreamCrew(StreamCrewEnum::VOTING, [], []);

        self::assertSame(0, $crew->people());
        self::assertSame(0, $crew->carriers());
        self::assertSame(0, $crew->previousCarriers());
        self::assertSame([], $crew->carrierShares());
    }
}
