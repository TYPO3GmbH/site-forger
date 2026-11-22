<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\StreamIntervalEnum;
use App\Enum\StreamRangeEnum;
use App\Search\StreamInsightsQuery;
use App\Search\StreamWindow;
use PHPUnit\Framework\TestCase;

final class StreamInsightsQueryTest extends TestCase
{
    public function testTheWaitsCountPerBandAndTheChangesEndMergedOrAbandoned(): void
    {
        $now = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('UTC'));

        $request = StreamInsightsQuery::durationRequest(StreamWindow::ofRange(StreamRangeEnum::WEEK, $now), null);

        $durations = $request['aggs'][StreamInsightsQuery::DURATIONS];
        $bands = $durations['aggs'][StreamInsightsQuery::BANDS]['range']['ranges'];
        self::assertSame(['key' => 'hour', 'to' => 3600], $bands[0]);
        self::assertSame(['key' => 'longer', 'from' => 28 * 86400], $bands[4]);
        self::assertSame(
            ['merged', 'closed'],
            $durations['filters']['filters']['time-to-close']['bool']['filter'][1]['terms']['kind'],
        );
    }

    public function testAPickedIntervalHasToFitTheWindow(): void
    {
        $now = new \DateTimeImmutable('2026-11-21 12:00:00', new \DateTimeZone('UTC'));
        $year = StreamWindow::ofRange(StreamRangeEnum::YEAR, $now);
        $week = StreamWindow::ofRange(StreamRangeEnum::WEEK, $now);

        self::assertSame('month', $year->withInterval(StreamIntervalEnum::MONTH)->interval());
        self::assertSame('week', $year->withInterval(StreamIntervalEnum::HOUR)->interval());
        self::assertSame([StreamIntervalEnum::HOUR, StreamIntervalEnum::DAY], $week->intervals());
    }
}
