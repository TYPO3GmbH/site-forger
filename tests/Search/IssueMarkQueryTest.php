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

    public function testAGoodFirstIssueIsEasyUnstartedAndUnassigned(): void
    {
        $mark = IssueMarkQuery::issues(IssueMarkEnum::GOOD_FIRST_ISSUE)['bool'];

        self::assertSame([IssueSearch::closed()], $mark['must_not']);
        self::assertSame(
            ['bool' => [
                'filter' => [
                    ['terms' => ['complexity' => ['no-brainer', 'trivial', 'easy']]],
                    ['terms' => ['status.id' => [1, 2]]],
                ],
                'must_not' => [['exists' => ['field' => 'assigned_to.id']]],
            ]],
            $mark['filter'][0],
        );
    }

    public function testTheAggregationCountsEachMarkByItsLabel(): void
    {
        $filters = IssueMarkQuery::aggregation()['filters']['filters'];

        self::assertSame(['Regression', 'Good first issue'], array_keys($filters));
        self::assertSame(IssueMarkQuery::issues(IssueMarkEnum::REGRESSION), $filters['Regression']);
        self::assertSame(IssueMarkQuery::issues(IssueMarkEnum::GOOD_FIRST_ISSUE), $filters['Good first issue']);
    }

    public function testTheFilterShowsEveryMarkInItsOrder(): void
    {
        $buckets = new ArrayCollection([Bucket::fromArray(['key' => 'Regression', 'doc_count' => 4])]);
        $facet = IssueMarkQuery::facet($buckets);

        self::assertEquals(
            [
                Bucket::fromArray(['key' => 'Regression', 'doc_count' => 4]),
                Bucket::fromArray(['key' => 'Good first issue', 'doc_count' => 0]),
            ],
            $facet->getValues(),
        );
    }
}
