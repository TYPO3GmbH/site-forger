<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Search;

use App\Search\DuplicateQuery;
use App\Search\IssueSearch;
use PHPUnit\Framework\TestCase;

final class DuplicateQueryTest extends TestCase
{
    public function testComparesSubjectAndDescriptionApartWithTheIssueItself(): void
    {
        $should = DuplicateQuery::query(91089)['bool']['should'];

        self::assertSame(['subject'], $should[0]['more_like_this']['fields']);
        self::assertSame(['description'], $should[1]['more_like_this']['fields']);
        self::assertGreaterThan($should[1]['more_like_this']['boost'], $should[0]['more_like_this']['boost']);
        foreach ($should as $clause) {
            self::assertSame([['_id' => '91089']], $clause['more_like_this']['like']);
            self::assertTrue($clause['more_like_this']['include']);
            self::assertSame(1, $clause['more_like_this']['min_term_freq']);
        }
    }

    public function testFindsOpenIssuesAndTheIssueItself(): void
    {
        self::assertSame([['bool' => ['should' => [
            ['ids' => ['values' => ['91089']]],
            ['bool' => ['must_not' => [IssueSearch::closed()]]],
        ]]]], DuplicateQuery::query(91089)['bool']['filter']);
    }

    public function testTheCheckFromTheOtherSideFindsOnlyTheTwoIssues(): void
    {
        $query = DuplicateQuery::between(91100, 91089)['bool'];

        self::assertSame([['ids' => ['values' => ['91100', '91089']]]], $query['filter']);
        self::assertSame([['_id' => '91100']], $query['should'][0]['more_like_this']['like']);
    }

    public function testComparesATextAsSubjectAndDescription(): void
    {
        $query = DuplicateQuery::likeText('Image crop is ignored in the preview')['bool'];

        self::assertArrayNotHasKey('must_not', $query);
        foreach ($query['should'] as $clause) {
            self::assertSame(
                [['doc' => [
                    'subject' => 'Image crop is ignored in the preview',
                    'description' => 'Image crop is ignored in the preview',
                ]]],
                $clause['more_like_this']['like'],
            );
            self::assertFalse($clause['more_like_this']['include']);
        }
    }
}
