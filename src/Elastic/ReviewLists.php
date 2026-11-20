<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use App\Enum\ReviewStatusEnum;

/**
 * Which Gerrit changes each review list shows, by the name of its route.
 *
 * The lists and the search in their "Owner" filter ask with the same query, so the owners and the
 * numbers it suggests are the ones the filter shows.
 */
final class ReviewLists
{
    public const string READY = 'gerrit_index';
    public const string REVIEWED_AND_VERIFIED = 'gerrit_hasverifications';
    public const string NEEDS_VERIFICATION = 'gerrit_missesverifications';
    public const string NEEDS_REVIEW = 'gerrit_missesreview';
    public const string NEEDS_REBASE = 'gerrit_rebase';
    public const string NEGATIVE_VOTES = 'gerrit_negative_vote_cleanup';
    public const string WORK_IN_PROGRESS = 'gerrit_wip';
    public const string READY_TO_MERGE = 'gerrit_coreteam_merge';
    /**
     * The changes of the search, also work in progress, and also merged and abandoned ones if asked for.
     */
    public const string SEARCH = 'search_changes';

    /**
     * The query of the list, null for a name that is no list.
     *
     * @param bool   $includeClosed whether the search finds merged and abandoned changes, too
     * @param string $searchTerms   words that narrow down the list, see ReviewSearch
     *
     * @return array<string, mixed>|null
     */
    public static function query(string $list, bool $includeClosed = false, string $searchTerms = ''): ?array
    {
        $listQuery = self::listQuery($list, $includeClosed);
        $search = ReviewSearch::query($searchTerms);
        if (null === $listQuery || null === $search) {
            return $listQuery;
        }

        // Not added to the list's own clauses: next to a "must" its "should" would no longer be required
        return ['bool' => ['must' => [$listQuery, $search]]];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function listQuery(string $list, bool $includeClosed): ?array
    {
        $closed = ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]];
        $workInProgress = ['term' => ['work_in_progress' => true]];
        $open = [$closed, $workInProgress];

        return match ($list) {
            self::READY => self::bool(
                must: [['terms' => ['reviewstatus' => [ReviewStatusEnum::NEW->value]]]],
                mustNot: $open
            ),
            self::REVIEWED_AND_VERIFIED => self::bool(
                must: [self::range('positive_reviews', 'gte'), self::range('positive_verifications', 'gte')],
                mustNot: $open
            ),
            self::NEEDS_VERIFICATION => self::bool(
                must: [self::range('positive_reviews', 'gte'), self::range('positive_verifications', 'lt')],
                mustNot: $open
            ),
            self::NEEDS_REVIEW => self::bool(
                must: [self::range('positive_reviews', 'lt'), self::range('positive_verifications', 'gte')],
                mustNot: $open
            ),
            self::NEEDS_REBASE => self::bool(
                must: [['term' => ['mergeable' => 'no']]],
                mustNot: $open
            ),
            self::NEGATIVE_VOTES => self::bool(
                should: [self::range('negative_reviews', 'gte'), self::range('negative_verifications', 'gte')],
                mustNot: $open
            ),
            self::WORK_IN_PROGRESS => self::bool(
                must: [$workInProgress],
                mustNot: [$closed]
            ),
            self::READY_TO_MERGE => self::bool(
                must: [
                    self::range('positive_reviews', 'gte'),
                    self::range('positive_verifications', 'gte'),
                    ['term' => ['mergeable' => 'yes']],
                ],
                mustNot: [...$open, self::range('negative_reviews', 'gte'), self::range('negative_verifications', 'gte')]
            ),
            self::SEARCH => $includeClosed ? ['match_all' => new \stdClass()] : self::bool(mustNot: [$closed]),
            default => null,
        };
    }

    /**
     * The changes that touch a file, or a file in a folder: the filter "File" of the review lists and of the search.
     * The path as in the repository, like "typo3/sysext/core/Classes/Cache/CacheManager.php", a "/" around it counts
     * for nothing. Null for no path.
     *
     * @return array<string, mixed>|null
     */
    public static function fileQuery(string $path): ?array
    {
        $path = trim(trim($path), '/');
        if ('' === $path) {
            return null;
        }

        return ['bool' => [
            'should' => [['term' => ['files' => $path]], ['prefix' => ['files' => $path . '/']]],
            'minimum_should_match' => 1,
        ]];
    }

    /**
     * "At least one" or "none" of the votes.
     *
     * @return array<string, mixed>
     */
    private static function range(string $field, string $operator): array
    {
        return ['range' => [$field => [$operator => 1]]];
    }

    /**
     * @param list<array<string, mixed>> $must
     * @param list<array<string, mixed>> $should
     * @param list<array<string, mixed>> $mustNot
     *
     * @return array<string, mixed>
     */
    private static function bool(array $must = [], array $should = [], array $mustNot = []): array
    {
        return ['bool' => array_filter(['must' => $must, 'should' => $should, 'must_not' => $mustNot])];
    }
}
