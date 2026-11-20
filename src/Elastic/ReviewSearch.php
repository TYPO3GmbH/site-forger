<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

/**
 * Which Gerrit changes search words find, in the review lists and in the search.
 *
 * The words are searched in the subject and the commit message, the subject counting more. A number also finds the
 * change with that number and the changes whose footer resolves or names the issue with it, a Change-Id the change
 * it starts, a word the topic it names and the owners whose name contains it.
 */
final class ReviewSearch
{
    /**
     * The query for the words, null without words: then a list shows all of its changes.
     *
     * @return array<string, mixed>|null
     */
    public static function query(string $searchTerms): ?array
    {
        $searchTerms = trim($searchTerms);
        if ('' === $searchTerms) {
            return null;
        }
        $should = [
            ['query_string' => ['query' => IssueSearch::escape($searchTerms), 'fields' => ['subject^3', 'commit_message'], 'default_operator' => 'AND']],
            ['term' => ['topic' => $searchTerms]],
            ['wildcard' => ['owner' => ['value' => '*' . addcslashes($searchTerms, '\\*?') . '*', 'case_insensitive' => true]]],
        ];
        if (1 === preg_match('/^#?\d{1,9}$/', $searchTerms)) {
            $searchTerms = ltrim($searchTerms, '#');
            // The number of the change itself comes before the changes that mention it
            $should[] = ['term' => ['id' => ['value' => (int) $searchTerms, 'boost' => 10]]];
            $should[] = ['term' => ['resolves' => ['value' => (int) $searchTerms, 'boost' => 5]]];
            $should[] = ['term' => ['related' => (int) $searchTerms]];
        }
        if (1 === preg_match('/^I[0-9a-f]{6,40}$/i', $searchTerms)) {
            $should[] = ['prefix' => ['change_id' => ['value' => 'I' . strtolower(substr($searchTerms, 1)), 'boost' => 10]]];
        }

        return ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
    }
}
