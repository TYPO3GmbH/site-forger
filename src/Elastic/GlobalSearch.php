<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use Elastica\Exception\NotFoundException;
use Elastica\Query;

/**
 * The search finds issues and Gerrit changes, each in its own tab. Both tabs show how many each of them finds.
 *
 * Forge issues and Gerrit changes are numbered alike, so a number finds the issue and the change with it, shown
 * above the results of either tab. The number also stays a search word: it finds the issues that mention it.
 */
final class GlobalSearch
{
    public const string ISSUES = 'issues';
    public const string CHANGES = 'changes';

    /**
     * How many issues and changes the search words find. An array given to Query is the whole request.
     *
     * @return array{issues: int, changes: int}
     */
    public static function tabs(string $searchTerms, bool $includeClosed): array
    {
        return [
            self::ISSUES => ElasticUtility::getIndexByName('issue')->count(new Query(['query' => IssueSearch::query($searchTerms, $includeClosed)])),
            self::CHANGES => ElasticUtility::getIndexByName('review')->count(new Query(['query' => ReviewLists::query(ReviewLists::SEARCH, '', $includeClosed, $searchTerms)])),
        ];
    }

    /**
     * The issue and the change with the number searched for, as they are indexed, closed ones, too.
     *
     * @return array{issue: array<string, mixed>|null, change: array<string, mixed>|null}
     */
    public static function exactMatches(string $searchTerms): array
    {
        $number = self::number($searchTerms);
        if (null === $number) {
            return ['issue' => null, 'change' => null];
        }

        return ['issue' => self::document('issue', $number), 'change' => self::document('review', $number)];
    }

    /**
     * The number the search words are, alone and with an optional "#", or null.
     */
    public static function number(string $searchTerms): ?int
    {
        if (1 !== preg_match('/^#?(?<number>[1-9]\d{0,8})$/', trim($searchTerms), $matches)) {
            return null;
        }

        return (int) $matches['number'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function document(string $type, int $id): ?array
    {
        try {
            return ElasticUtility::getIndexByName($type)->getDocument((string) $id)->getData();
        } catch (NotFoundException) {
            return null;
        }
    }
}
