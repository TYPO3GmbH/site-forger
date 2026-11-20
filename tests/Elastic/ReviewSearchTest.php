<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\ReviewSearch;
use PHPUnit\Framework\TestCase;

final class ReviewSearchTest extends TestCase
{
    public function testNoWordsNarrowNothingDown(): void
    {
        self::assertNull(ReviewSearch::query(''));
        self::assertNull(ReviewSearch::query('  '));
    }

    public function testWordsAreSearchedInTheSubjectTopicAndOwner(): void
    {
        $should = ReviewSearch::query(' form (editor) ')['bool']['should'];

        self::assertSame(['query_string' => ['query' => 'form \\(editor\\)', 'fields' => ['subject^3', 'commit_message'], 'default_operator' => 'AND']], $should[0]);
        self::assertSame(['term' => ['topic' => 'form (editor)']], $should[1]);
        self::assertSame(['wildcard' => ['owner' => ['value' => '*form (editor)*', 'case_insensitive' => true]]], $should[2]);
        self::assertCount(3, $should);
    }

    public function testANumberFindsItsChange(): void
    {
        self::assertContains(['term' => ['id' => ['value' => 81234, 'boost' => 10]]], ReviewSearch::query('81234')['bool']['should']);
    }

    public function testAnIssueNumberFindsTheChangesOfItsFooter(): void
    {
        $should = ReviewSearch::query('#105123')['bool']['should'];

        self::assertContains(['term' => ['resolves' => ['value' => 105123, 'boost' => 5]]], $should);
        self::assertContains(['term' => ['related' => 105123]], $should);
    }

    public function testAChangeIdFindsItsChange(): void
    {
        self::assertContains(
            ['prefix' => ['change_id' => ['value' => 'I0123abcdef', 'boost' => 10]]],
            ReviewSearch::query('I0123ABCDEF')['bool']['should']
        );
    }

    public function testOneOfThemIsEnough(): void
    {
        self::assertSame(1, ReviewSearch::query('cache')['bool']['minimum_should_match']);
    }
}
