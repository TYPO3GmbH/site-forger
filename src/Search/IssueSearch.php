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
     * A search of more words is a text, like a pasted report, and is searched like the duplicates of an issue. With
     * the search below a single common word of it would be enough for a hit.
     */
    public const int TEXT_WORDS = 8;

    /**
     * The fields the search words are searched in: what Forge shows of an issue. Without a list Elasticsearch
     * searches every field, also the email addresses the index keeps for the avatars. A search for an address would
     * then find the issues of its owner, also of people who hide it on Forge.
     */
    public const array SEARCHED_FIELDS = [
        'id',
        'subject',
        'description',
        'tags',
        'tracker.name',
        'status.name',
        'priority.name',
        'category.name',
        'fixed_version.name',
        'target_version',
        'project.name',
        'focus.name',
        'complexity',
        'typo3_version',
        'php_version',
        'author.name',
        'assigned_to.name',
        'custom_fields.name',
        'custom_fields.value',
        'journals.notes',
        'journals.user.name',
        'journals.details.name',
        'journals.details.old_value',
        'journals.details.new_value',
    ];

    /**
     * @param bool $includeClosed whether resolved, closed and rejected issues are found, too
     *
     * @return array<string, mixed>
     */
    public static function query(string $searchTerms, bool $includeClosed = false): array
    {
        // Without search terms all issues are found, so links can lead straight to a filtered list
        $searchTerms = trim($searchTerms);
        $match = match (true) {
            '' === $searchTerms => ['match_all' => new \stdClass()],
            self::isText($searchTerms) => DuplicateQuery::likeText($searchTerms),
            default => ['query_string' => [
                'query' => self::escape($searchTerms),
                'fields' => self::SEARCHED_FIELDS,
                // A word in a number field, like "id", matches nothing instead of failing the search
                'lenient' => true,
            ]],
        };
        $bool = ['must' => [$match]];
        if (!$includeClosed) {
            $bool['must_not'] = [self::closed()];
        }

        return ['bool' => $bool];
    }

    /**
     * Whether the search words are a text rather than words to look for, see TEXT_WORDS.
     */
    public static function isText(string $searchTerms): bool
    {
        return count(preg_split('/\s+/', trim($searchTerms), -1, PREG_SPLIT_NO_EMPTY) ?: []) > self::TEXT_WORDS;
    }

    /**
     * The clause of the resolved, closed and rejected issues, by the id of their state.
     *
     * @return array<string, mixed>
     */
    public static function closed(): array
    {
        return ['terms' => ['status.id' => array_map(
            static fn (IssueStatusEnum $state): int => $state->value,
            IssueStatusEnum::closed(),
        )]];
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
        // The operators in capitals: "foo AND" fails the query, in small letters they are words
        $words = preg_replace_callback(
            '/\b(AND|OR|NOT)\b/',
            static fn (array $match): string => strtolower($match[1]),
            $escapedString ?? $value,
        );

        return str_replace('/', '\/', $words ?? $value);
    }
}
