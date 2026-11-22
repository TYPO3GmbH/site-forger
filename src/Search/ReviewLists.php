<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\ActivityEnum;
use App\Enum\ReviewSizeEnum;
use App\Enum\ReviewStatusEnum;
use App\People\UserDirectory;
use App\Report\MonthOverview;
use Elastica\Query\BoolQuery;
use Elastica\Query\Range;
use Elastica\Query\Simple;
use Elastica\Query\Term;

/**
 * Which Gerrit changes each review list shows, by the name of its route.
 *
 * The lists and the search in their "Owner" filter ask with the same query, so the owners and the
 * numbers it suggests are the ones the filter shows.
 */
final class ReviewLists
{
    public const string READY = 'reviews_ready';
    public const string REVIEWED_AND_VERIFIED = 'reviews_verified';
    public const string NEEDS_VERIFICATION = 'reviews_needs_verification';
    public const string NEEDS_REVIEW = 'reviews_needs_review';
    public const string NEEDS_REBASE = 'reviews_needs_rebase';
    public const string NEGATIVE_VOTES = 'reviews_negative_votes';
    public const string WORK_IN_PROGRESS = 'reviews_wip';
    public const string READY_TO_MERGE = 'reviews_ready_to_merge';
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
        // A change with a -1 or -2 or with a merge conflict waits for its owner, not for a review
        $negativeVotes = [self::range('negative_reviews', 'gte'), self::range('negative_verifications', 'gte')];
        $mergeable = ['term' => ['mergeable' => 'yes']];

        return match ($list) {
            self::READY => self::bool(
                must: [['terms' => ['reviewstatus' => [ReviewStatusEnum::NEW->value]]], $mergeable],
                mustNot: [...$open, ...$negativeVotes]
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
                    $mergeable,
                ],
                mustNot: [...$open, ...$negativeVotes]
            ),
            self::SEARCH => $includeClosed ? ['match_all' => new \stdClass()] : self::bool(mustNot: [$closed]),
            default => null,
        };
    }

    /**
     * The changes that touch a file, or a file in a folder: the filter "File" of the review lists and of the search.
     * The path as in the repository, like "typo3/sysext/core/Classes/Cache/CacheManager.php", a "/" around it counts
     * for nothing. A "*" stands for any characters, "/" included: "*.rst" finds the changes of any ".rst" file.
     * Several paths are separated by commas, a change touches each of them. Null for no path.
     *
     * @return array<string, mixed>|null
     */
    public static function fileQuery(string $paths): ?array
    {
        $queries = array_map(self::pathQuery(...), self::filePaths($paths));

        return match (count($queries)) {
            0 => null,
            1 => $queries[0],
            default => ['bool' => ['must' => $queries]],
        };
    }

    /**
     * The paths of the filter "File", each once, without a "/" around it: "*.rst, typo3/sysext/core/" has "*.rst"
     * and "typo3/sysext/core".
     *
     * @return list<string>
     */
    public static function filePaths(string $paths): array
    {
        $parts = array_map(static fn (string $path): string => trim(trim($path), '/'), explode(',', $paths));

        return array_values(array_unique(array_filter($parts, static fn (string $path): bool => '' !== $path)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function pathQuery(string $path): array
    {
        if (str_contains($path, '*')) {
            return ['wildcard' => ['files' => ['value' => self::filePattern($path)]]];
        }

        return ['bool' => [
            'should' => [['term' => ['files' => $path]], ['prefix' => ['files' => $path . '/']]],
            'minimum_should_match' => 1,
        ]];
    }

    /**
     * The filters of the lists, by their title. The first ones are what reviewers pick by.
     *
     * @param list<string> $without the filters a list leaves out, because all of its changes have the same value
     *
     * @return array<string, mixed>
     */
    public static function aggregations(array $without = []): array
    {
        $aggregations = [
            // Added and removed lines together, filtered as "filters[size][...]"
            'Size' => ReviewSizeQuery::changeAggregation(),
            'Releases' => ['terms' => ['field' => 'releases']],
            'Affected Files' => ['terms' => ['field' => 'affected_files']],
            'Topic' => ['terms' => ['field' => 'topic', 'order' => ['_term' => 'asc']]],
            'Patchsets' => ['terms' => ['field' => 'patchsets']],
            'Mergeable' => ['terms' => ['field' => 'mergeable']],
            // The owners with the most changes. The search in the filter finds the others.
            'Owner' => PeopleFacet::aggregation('Owner'),
            // The tags in the subject, like "BUGFIX" or "!!!", filtered as "filters[type][...]"
            'Type' => ['terms' => ['field' => 'reviewtags', 'size' => 50]],
            'Activity' => ActivityQuery::changeAggregation(),
        ];

        return array_diff_key($aggregations, array_flip($without));
    }

    /**
     * The query of the selected filters: "filters[<key>][<value>]=true", any value of a filter and all filters.
     *
     * @param array<string, mixed> $filters
     */
    public static function filterQuery(array $filters, UserDirectory $users): BoolQuery
    {
        $query = new BoolQuery();
        foreach ($filters as $key => $values) {
            $filter = match ((string) $key) {
                'file' => self::simple(self::fileQuery(is_array($values) ? (string) ($values['path'] ?? '') : '')),
                'updated' => self::simple(self::updatedQuery(is_array($values) ? $values : [])),
                'owner' => self::simple(PeopleFacet::query('Owner', FacetSelection::keys($filters, 'owner'), $users)),
                default => is_array($values) ? self::anyOf((string) $key, $values) : null,
            };
            if (null !== $filter) {
                $query->addMust($filter);
            }
        }

        return $query;
    }

    /**
     * Elasticsearch reads "?" as any one character and "\" as an escape, a path means them as they are.
     */
    private static function filePattern(string $path): string
    {
        return strtr($path, ['\\' => '\\\\', '?' => '\\?']);
    }

    /**
     * @param array<string, mixed>|null $query
     */
    private static function simple(?array $query): ?Simple
    {
        return null === $query ? null : new Simple($query);
    }

    /**
     * @param array<string, mixed> $range the days of the filter, see MonthOverview::days()
     *
     * @return array<string, mixed>|null
     */
    private static function updatedQuery(array $range): ?array
    {
        $days = MonthOverview::days($range);

        return MonthOverview::rangeQuery($days['from'], $days['to'], 'updated_on');
    }

    /**
     * @param array<int|string, mixed> $values
     */
    private static function anyOf(string $key, array $values): ?BoolQuery
    {
        $any = new BoolQuery();
        $count = 0;
        foreach ($values as $value => $selected) {
            if ('true' !== $selected) {
                continue;
            }
            $filter = self::valueQuery($key, str_replace('_', ' ', (string) $value));
            if (null !== $filter) {
                $any->addShould($filter);
                ++$count;
            }
        }

        return $count > 0 ? $any : null;
    }

    private static function valueQuery(string $key, string $value): Simple|Range|Term|null
    {
        switch ($key) {
            case 'activity':
                // Only open changes have a stage, also when closed ones are searched
                $stage = ActivityEnum::tryFromLabel($value);

                return null === $stage ? null : new Simple(ActivityQuery::changes($stage));
            case 'size':
                $size = ReviewSizeEnum::tryFromLabel($value);

                return null === $size ? null : new Simple(ReviewSizeQuery::changes($size));
            case 'updated_on':
                // A month of the filter "Updated on" ticked before the calendar, in links shared then
                $start = new \DateTimeImmutable('@' . intdiv((int) $value, 1000));

                return new Range($key, ['gte' => $start->format('Y/m/d H:i:s'), 'lte' => $start->modify('+1 month -1 second')->format('Y/m/d H:i:s')]);
            case 'type':
                return new Term(['reviewtags' => $value]);
            default:
                return new Term([$key => $value]);
        }
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
