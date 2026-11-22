<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Search\ReviewQueueQuery;
use PHPUnit\Framework\TestCase;

final class ReviewQueueQueryTest extends TestCase
{
    public function testTheOpenChangesOfABranchCountByTheirLastActivityWithoutWorkInProgress(): void
    {
        $request = ReviewQueueQuery::request('main');

        self::assertSame(
            [['term' => ['reviewstatus' => 'NEW']], ['term' => ['branch' => 'main']]],
            $request['query']['bool']['filter'],
        );
        self::assertSame([['term' => ['work_in_progress' => true]]], $request['query']['bool']['must_not']);
        $ages = $request['aggs'][ReviewQueueQuery::AGES]['date_range'];
        self::assertSame('updated_on', $ages['field']);
        self::assertSame(['key' => 'week', 'from' => 'now-7d/d'], $ages['ranges'][0]);
        self::assertSame(['key' => 'longer', 'to' => 'now-365d/d'], $ages['ranges'][4]);
    }
}
