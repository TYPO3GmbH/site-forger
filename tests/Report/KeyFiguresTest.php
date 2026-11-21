<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Report;

use App\People\UserDirectory;
use App\Report\KeyFigures;
use App\Report\MonthOverview;
use App\Search\ReviewLists;
use PHPUnit\Framework\TestCase;

final class KeyFiguresTest extends TestCase
{
    public function testTheWaitingChangesEndThirtyDaysAgo(): void
    {
        $filter = KeyFigures::waitingFilter(new \DateTimeImmutable('2026-11-21 10:00:00', new \DateTimeZone('UTC')));

        self::assertSame(['updated' => ['range' => '2000-01-01 to 2026-10-21']], $filter);
        // The last day is included: the changes updated before the day 30 days ago, as "now-30d/d" counted before
        $days = MonthOverview::days($filter['updated']);
        self::assertSame(
            ['range' => ['updated_on' => ['gte' => '2000/01/01 00:00:00', 'lt' => '2026/10/22 00:00:00']]],
            MonthOverview::rangeQuery($days['from'], $days['to'], 'updated_on'),
        );
    }

    public function testTheDayIsTheDayInUtc(): void
    {
        // Shortly after midnight in Berlin it is still the day before in UTC
        $today = new \DateTimeImmutable('2026-11-21 00:30:00', new \DateTimeZone('Europe/Berlin'));

        self::assertSame('2000-01-01 to 2026-10-20', KeyFigures::waitingFilter($today)['updated']['range']);
    }

    public function testTheLinkOpensTheListTheNumberCounts(): void
    {
        $filter = KeyFigures::waitingFilter(new \DateTimeImmutable('2026-11-21 10:00:00', new \DateTimeZone('UTC')));
        $query = ReviewLists::filterQuery($filter, self::createStub(UserDirectory::class))->toArray();

        self::assertSame('2026/10/22 00:00:00', $query['bool']['must'][0]['range']['updated_on']['lt']);
    }
}
