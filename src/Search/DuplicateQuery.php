<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

/**
 * The query for the open issues most like a given one, see DuplicateSearch.
 *
 * Subject and description go through the English analyzer of the issue index: stop words, stems, no Textile
 * markup. So the query is made of the words that set the issue apart, not of "the", "in" and "when". The subject
 * names the problem in few words and counts three times. The description adds the details.
 */
final class DuplicateQuery
{
    /**
     * How many terms of the subject and of the description the query is made of.
     */
    private const int SUBJECT_TERMS = 10;

    private const int DESCRIPTION_TERMS = 15;

    private const int SUBJECT_BOOST = 3;

    /**
     * Finds open issues only. The issue itself is found even when closed: its score is the measure for the others.
     *
     * @return array<string, mixed>
     */
    public static function query(int $id): array
    {
        return self::like($id, ['bool' => ['should' => [
            ['ids' => ['values' => [(string) $id]]],
            ['bool' => ['must_not' => [IssueSearch::closed()]]],
        ]]]);
    }

    /**
     * The query of an issue for the other issue only, with the issue itself as the measure: how similar the other
     * one is, seen from this issue. DuplicateSearch checks each hit this way from the hit's side.
     *
     * @return array<string, mixed>
     */
    public static function between(int $id, int $other): array
    {
        return self::like($id, ['ids' => ['values' => [(string) $id, (string) $other]]]);
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return array<string, mixed>
     */
    private static function like(int $id, array $filter): array
    {
        return ['bool' => [
            'should' => [
                self::moreLikeThis($id, 'subject', self::SUBJECT_TERMS, self::SUBJECT_BOOST),
                self::moreLikeThis($id, 'description', self::DESCRIPTION_TERMS, 1),
            ],
            'minimum_should_match' => 1,
            'filter' => [$filter],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function moreLikeThis(int $id, string $field, int $terms, int $boost): array
    {
        return ['more_like_this' => [
            'fields' => [$field],
            'like' => [['_id' => (string) $id]],
            // Subjects name each word once
            'min_term_freq' => 1,
            // A word no other issue has finds nothing
            'min_doc_freq' => 2,
            'max_query_terms' => $terms,
            'minimum_should_match' => '30%',
            'include' => true,
            'boost' => $boost,
        ]];
    }
}
