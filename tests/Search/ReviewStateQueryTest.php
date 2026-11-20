<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\ReviewStateEnum;
use App\Search\ReviewStateQuery;
use PHPUnit\Framework\TestCase;

final class ReviewStateQueryTest extends TestCase
{
    public function testTheOpenStatesAreToldApartByTheWorkInProgressFlag(): void
    {
        self::assertSame(
            ['bool' => [
                'filter' => [['term' => ['reviewstatus' => 'NEW']]],
                'must_not' => [['term' => ['work_in_progress' => true]]],
            ]],
            ReviewStateQuery::changes(ReviewStateEnum::READY),
        );
        self::assertSame(
            ['term' => ['reviewstatus' => 'MERGED']],
            ReviewStateQuery::changes(ReviewStateEnum::MERGED),
        );
    }
}
