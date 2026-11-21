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

final class IssueSearchTest extends TestCase
{
    public function testFindsOpenIssuesForTheSearchWords(): void
    {
        self::assertSame(
            ['bool' => [
                'must' => [['query_string' => [
                    'query' => 'form \\(editor\\)',
                    'fields' => IssueSearch::SEARCHED_FIELDS,
                    'lenient' => true,
                ]]],
                'must_not' => [['terms' => ['status.id' => [5, 6, 3]]]],
            ]],
            IssueSearch::query(' form (editor) ')
        );
    }

    public function testDoesNotSearchTheEmailAddresses(): void
    {
        // The index keeps them for the avatars, a search must not tell whose they are
        foreach (IssueSearch::SEARCHED_FIELDS as $field) {
            self::assertStringNotContainsStringIgnoringCase('email', $field);
            self::assertStringNotContainsString('*', $field);
        }
    }

    public function testSearchesALongTextLikeTheDuplicates(): void
    {
        $text = 'The crop of an image is ignored in the preview of the page module';

        self::assertTrue(IssueSearch::isText($text));
        self::assertSame([DuplicateQuery::likeText($text)], IssueSearch::query($text, true)['bool']['must']);
    }

    public function testSearchesAFewWordsAsWords(): void
    {
        self::assertFalse(IssueSearch::isText(' image  crop preview '));
        self::assertArrayHasKey('query_string', IssueSearch::query('image crop preview', true)['bool']['must'][0]);
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
