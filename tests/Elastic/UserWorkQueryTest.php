<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\UserWorkQuery;
use PHPUnit\Framework\TestCase;

final class UserWorkQueryTest extends TestCase
{
    public function testIssuesMatchEmailOrNameAndExcludeClosedStates(): void
    {
        $request = (new UserWorkQuery('Jane.Doe@Example.org', 'Jane Doe'))->issues();

        self::assertSame(
            [
                'should' => [
                    ['terms' => ['author.email' => ['Jane.Doe@Example.org', 'jane.doe@example.org']]],
                    ['terms' => ['author.name' => ['Jane Doe']]],
                    ['terms' => ['assigned_to.name.keyword' => ['Jane Doe']]],
                ],
                'minimum_should_match' => 1,
                'must_not' => [['terms' => ['status.name' => ['Closed', 'Rejected', 'Resolved']]]],
            ],
            $request['query']['bool']
        );
        self::assertSame(['updated_on' => 'desc'], $request['sort']);
    }

    public function testReviewsMatchOwnerAndExcludeMergedAndAbandoned(): void
    {
        $request = (new UserWorkQuery('jane@example.org', 'Jane Doe'))->reviews();

        self::assertSame(
            [
                'should' => [
                    ['terms' => ['ownerEmail' => ['jane@example.org']]],
                    ['terms' => ['owner' => ['Jane Doe']]],
                ],
                'minimum_should_match' => 1,
                'must_not' => [['terms' => ['reviewstatus' => ['MERGED', 'ABANDONED']]]],
            ],
            $request['query']['bool']
        );
    }

    public function testIncludeClosedDropsTheStateFilter(): void
    {
        $request = (new UserWorkQuery('jane@example.org', 'Jane Doe', true))->reviews();

        self::assertArrayNotHasKey('must_not', $request['query']['bool']);
    }

    public function testEmptyIdentityMatchesNothing(): void
    {
        $request = (new UserWorkQuery(' ', ''))->issues();

        self::assertArrayNotHasKey('should', $request['query']['bool']);
        self::assertArrayHasKey('must_not', $request['query']['bool']);
        self::assertEquals([['match_all' => new \stdClass()]], $request['query']['bool']['must_not']);
    }
}
