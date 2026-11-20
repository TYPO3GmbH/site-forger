<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\IssueStatusEnum;

/**
 * Which issues the search finds for search words.
 *
 * The search and the search in its "Author" and "Assigned to" filters ask with the same query, so the people
 * and the numbers they suggest are the ones the filters show.
 */
final class IssueSearch
{
    /**
     * @param bool $includeClosed whether resolved, closed and rejected issues are found, too
     *
     * @return array<string, mixed>
     */
    public static function query(string $searchTerms, bool $includeClosed = false): array
    {
        // Without search terms all issues are found, so links can lead straight to a filtered list
        $searchTerms = trim($searchTerms);
        $match = '' === $searchTerms
            ? ['match_all' => new \stdClass()]
            : ['query_string' => ['query' => self::escape($searchTerms)]];
        $bool = ['must' => [$match]];
        if (!$includeClosed) {
            $bool['must_not'] = [['terms' => ['status.name' => IssueStatusEnum::closedLabels()]]];
        }

        return ['bool' => $bool];
    }

    /**
     * Escape a value for special query characters such as ':', '(', ')', '*', '?', etc.
     */
    public static function escape(string $value): string
    {
        // list taken from http://lucene.apache.org/java/docs/queryparsersyntax.html#Escaping%20Special%20Characters
        $pattern = '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\*|\?|:|\\\)/';
        $replace = '\\\$1';
        $escapedString = preg_replace($pattern, $replace, $value);

        return str_replace('/', '\/', $escapedString);
    }
}
