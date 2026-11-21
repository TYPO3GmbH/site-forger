<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Elastic\Bucket;
use App\Enum\IssueMarkEnum;
use App\Search\IssueMarkQuery;
use App\Search\IssueSearch;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class IssueMarkQueryTest extends TestCase
{
    public function testARegressionIsAnOpenIssueForgeMarks(): void
    {
        self::assertSame(
            ['bool' => [
                'filter' => [['term' => ['isregression' => true]]],
                'must_not' => [IssueSearch::closed()],
            ]],
            IssueMarkQuery::issues(IssueMarkEnum::REGRESSION),
        );
    }

    public function testTheAggregationCountsEachMarkByItsLabel(): void
    {
        $filters = IssueMarkQuery::aggregation()['filters']['filters'];

        self::assertSame(['Regression'], array_keys($filters));
        self::assertSame(IssueMarkQuery::issues(IssueMarkEnum::REGRESSION), $filters['Regression']);
    }

    public function testTheFilterShowsEveryMarkInItsOrder(): void
    {
        $buckets = new ArrayCollection([Bucket::fromArray(['key' => 'Regression', 'doc_count' => 4])]);
        $facet = IssueMarkQuery::facet($buckets);

        self::assertEquals([Bucket::fromArray(['key' => 'Regression', 'doc_count' => 4])], $facet->getValues());
    }
}
