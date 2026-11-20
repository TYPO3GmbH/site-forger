<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Enum\IssueStatusEnum;
use App\Search\IssueSearch;
use PHPUnit\Framework\TestCase;

final class IssueSearchTest extends TestCase
{
    public function testFindsOpenIssuesForTheSearchWords(): void
    {
        self::assertSame(
            ['bool' => [
                'must' => [['query_string' => ['query' => 'form \\(editor\\)']]],
                'must_not' => [['terms' => ['status.name' => IssueStatusEnum::closedLabels()]]],
            ]],
            IssueSearch::query(' form (editor) ')
        );
    }

    public function testFindsAllIssuesWithoutSearchWords(): void
    {
        $query = IssueSearch::query('', true);

        self::assertSame(['must'], array_keys($query['bool']));
        self::assertEquals([['match_all' => new \stdClass()]], $query['bool']['must']);
    }

    public function testEscapesSpecialCharacters(): void
    {
        self::assertSame('a\\:b \\"c\\" d\\/e', IssueSearch::escape('a:b "c" d/e'));
    }
}
