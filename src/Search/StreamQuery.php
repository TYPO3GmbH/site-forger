<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\StreamKindEnum;

/**
 * The search of the stream index for the activity stream: the newest events first.
 */
final class StreamQuery
{
    /**
     * Elasticsearch pages through this many events at most, see "index.max_result_window".
     */
    public const int MAX_WINDOW = 10000;

    public const string BRANCHES = 'branches';

    /**
     * The branches the filter offers: those of the most events in the window.
     */
    private const int BRANCH_COUNT = 15;

    /**
     * @return array<string, mixed>
     */
    public static function request(StreamFilter $filter, int $from, int $size): array
    {
        return [
            'query' => self::query($filter),
            'sort' => [['date' => 'desc'], ['id' => 'desc']],
            'from' => $from,
            'size' => $size,
            'track_total_hits' => self::MAX_WINDOW,
        ];
    }

    /**
     * The request of request() that also counts the branches of the events, for the filter of the page. The branch
     * filters only the events, not the counts: a "post_filter", so the other branches stay to pick.
     *
     * @return array<string, mixed>
     */
    public static function requestWithBranches(StreamFilter $filter, int $from, int $size): array
    {
        $request = [
            ...self::request(self::withoutBranch($filter), $from, $size),
            'aggs' => [self::BRANCHES => ['terms' => ['field' => 'branch', 'size' => self::BRANCH_COUNT]]],
        ];
        if (null !== $filter->branch) {
            $request['post_filter'] = ['term' => ['branch' => $filter->branch]];
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    public static function query(StreamFilter $filter): array
    {
        $filters = [];
        if (null !== $filter->window) {
            $filters[] = $filter->window->query();
        }
        if (null !== $filter->source) {
            $filters[] = ['term' => ['source' => $filter->source->value]];
        }
        if ([] !== $filter->kinds) {
            $filters[] = ['terms' => ['kind' => array_map(
                static fn (StreamKindEnum $kind): string => $kind->value,
                $filter->kinds,
            )]];
        }
        if (null !== $filter->branch) {
            $filters[] = ['term' => ['branch' => $filter->branch]];
        }
        if (!$filter->bots) {
            $filters[] = ['term' => ['bot' => false]];
        }
        if ($filter->hasPerson()) {
            $filters[] = self::person($filter);
        }

        return [] === $filters ? ['match_all' => new \stdClass()] : ['bool' => ['filter' => $filters]];
    }

    /**
     * The events of the person: by the numbers on Forge or Gerrit, else by the words of the name. The field is the
     * person of the event, "author" or "owner", see ReviewExchangeQuery.
     *
     * @return array<string, mixed>
     */
    public static function person(StreamFilter $filter, string $field = 'author'): array
    {
        $should = [];
        if ([] !== $filter->forgeIds) {
            $should[] = ['terms' => [$field . '.forge_id' => $filter->forgeIds]];
        }
        if ([] !== $filter->gerritIds) {
            $should[] = ['terms' => [$field . '.gerrit_id' => $filter->gerritIds]];
        }
        if ([] !== $filter->nameWords) {
            $should[] = ['bool' => ['must' => array_map(
                static fn (string $word): array => ['wildcard' => [$field . '.name' => [
                    'value' => '*' . addcslashes($word, '\\*?') . '*',
                    'case_insensitive' => true,
                ]]],
                $filter->nameWords,
            )]];
        }

        return ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
    }

    private static function withoutBranch(StreamFilter $filter): StreamFilter
    {
        return new StreamFilter(
            $filter->window,
            $filter->source,
            $filter->kinds,
            null,
            $filter->forgeIds,
            $filter->gerritIds,
            $filter->nameWords,
            $filter->bots,
        );
    }
}
