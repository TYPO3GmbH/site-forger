<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Search\ClosedPerYearQuery;
use PHPUnit\Framework\TestCase;

final class ClosedPerYearQueryTest extends TestCase
{
    public function testADocumentCountsByItsCloseAndWithoutOneByItsLastUpdate(): void
    {
        $request = ClosedPerYearQuery::issues(2025);

        $inYear = ['gte' => 2025, 'lt' => 2026, 'format' => 'yyyy'];
        $byClose = $request['aggs'][ClosedPerYearQuery::BY_CLOSE];
        $byUpdate = $request['aggs'][ClosedPerYearQuery::BY_UPDATE];
        self::assertSame(['range' => ['closed_on' => $inYear]], $byClose['filter']);
        self::assertSame([['range' => ['updated_on' => $inYear]]], $byUpdate['filter']['bool']['filter']);
        self::assertSame([['exists' => ['field' => 'closed_on']]], $byUpdate['filter']['bool']['must_not']);
        $months = $byClose['aggs'][ClosedPerYearQuery::MONTHS]['date_histogram'];
        self::assertSame('closed_on', $months['field']);
        self::assertSame('2025-01', $months['extended_bounds']['min']);
    }

    public function testTheChangesInProgressAreLeftOut(): void
    {
        $closed = ClosedPerYearQuery::changes(2025)['query']['bool']['filter'];

        self::assertSame(['MERGED', 'ABANDONED'], $closed[0]['terms']['reviewstatus']);
        self::assertSame([['term' => ['work_in_progress' => true]]], $closed[1]['bool']['must_not']);
    }
}
