<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\ReviewStatusEnum;
use App\Enum\StreamMilestoneEnum;

/**
 * The requests that find the firsts of new people in the issues and the changes themselves, not in the activity
 * stream: the stream only knows what the notes and the messages of the index tell, and an index may lack those of
 * old issues and changes. See App\Stream\NewcomerFinder.
 */
final class NewcomerQuery
{
    public const string PEOPLE = 'people';
    public const string FIRST = 'first';

    /**
     * Some thousand people have reported an issue since 2004: 20000 names are all of them.
     */
    private const int PEOPLE_SIZE = 20000;

    /**
     * The people who reached the milestone in the window or in the one before, each with the date of their first:
     * the first issue by its author, the first change by its owner, the first merged change by its owner. A merge
     * counts when the change was updated last, the merge or a note after it.
     *
     * It names no document: a first document for each of some thousand people took ten times as long as the
     * counting. firstsRequest() loads the documents of the few people a page lists.
     *
     * @return array<string, mixed>
     */
    public static function request(StreamMilestoneEnum $milestone, StreamWindow $window): array
    {
        [$person, $date] = self::fields($milestone);
        $since = $window->previous()->from->getTimestamp() * 1000;

        return [
            'size' => 0,
            'track_total_hits' => false,
            'query' => self::query($milestone),
            'aggs' => [self::PEOPLE => [
                'terms' => ['field' => $person, 'size' => self::PEOPLE_SIZE],
                'aggs' => [
                    self::FIRST => ['min' => ['field' => $date]],
                    'since' => ['bucket_selector' => [
                        'buckets_path' => ['first' => self::FIRST],
                        'script' => ['source' => 'params.first >= params.since', 'params' => ['since' => $since]],
                    ]],
                ],
            ]],
        ];
    }

    /**
     * The first issue or change of each of the people: one document per person, the oldest.
     *
     * @param list<string>           $people  as request() names them
     * @param list<non-empty-string> $without the large fields a list does not read
     *
     * @return array<string, mixed>
     */
    public static function firstsRequest(StreamMilestoneEnum $milestone, array $people, array $without): array
    {
        [$person, $date] = self::fields($milestone);

        return [
            'size' => count($people),
            'track_total_hits' => false,
            'query' => ['bool' => ['filter' => [self::query($milestone), ['terms' => [$person => $people]]]]],
            'sort' => [[$date => 'asc']],
            'collapse' => ['field' => $person],
            '_source' => ['excludes' => $without],
        ];
    }

    /**
     * @return array{string, string} the field of the person and the one of the date
     */
    private static function fields(StreamMilestoneEnum $milestone): array
    {
        return match ($milestone) {
            StreamMilestoneEnum::FIRST_ISSUE => ['author.name', 'created_on'],
            StreamMilestoneEnum::FIRST_CHANGE => ['owner', 'created_on'],
            StreamMilestoneEnum::FIRST_MERGE => ['owner', 'updated_on'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function query(StreamMilestoneEnum $milestone): array
    {
        return StreamMilestoneEnum::FIRST_MERGE === $milestone
            ? ['term' => ['reviewstatus' => ReviewStatusEnum::MERGED->value]]
            : ['match_all' => new \stdClass()];
    }
}
