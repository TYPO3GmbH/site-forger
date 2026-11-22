<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Elastic\Bucket;
use App\Enum\ReviewStatusEnum;
use App\Enum\WaitingForEnum;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * The queries and the aggregation of the filter "Waiting for" of the changes, see WaitingForEnum. Only open
 * changes that are no work in progress wait for someone.
 */
final class WaitingQuery
{
    public const string TITLE = 'Waiting for';

    /**
     * The key of the filter in "filters[<key>][<value>]".
     */
    public const string KEY = 'waiting_for';

    /**
     * The changes that wait for the owner: a negative vote, a merge conflict or unresolved comments.
     *
     * @return list<array<string, mixed>>
     */
    public static function ownerSteps(): array
    {
        return [
            ['range' => ['negative_reviews' => ['gte' => 1]]],
            ['range' => ['negative_verifications' => ['gte' => 1]]],
            ['term' => ['mergeable' => 'no']],
            ['range' => ['unresolved_comment_count' => ['gte' => 1]]],
        ];
    }

    /**
     * The changes that wait for the step. A user without an account on Gerrit owns no change and voted on none:
     * YOUR_VOTE finds all open changes then.
     *
     * @param list<int> $gerritIds the accounts of the user logged in
     *
     * @return array<string, mixed>
     */
    public static function changes(WaitingForEnum $step, array $gerritIds = []): array
    {
        $mustNot = [
            ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]],
            ['term' => ['work_in_progress' => true]],
        ];

        return match ($step) {
            WaitingForEnum::OWNER => ['bool' => [
                'should' => self::ownerSteps(),
                'minimum_should_match' => 1,
                'must_not' => $mustNot,
            ]],
            WaitingForEnum::FIRST_REVIEW => ['bool' => [
                'filter' => [
                    ['range' => ['positive_reviews' => ['lt' => 1]]],
                    ['range' => ['negative_reviews' => ['lt' => 1]]],
                ],
                'must_not' => [...$mustNot, ...self::ownerSteps()],
            ]],
            WaitingForEnum::YOUR_VOTE => ['bool' => ['must_not' => [
                ...$mustNot,
                ...([] === $gerritIds ? [] : [
                    ['terms' => ['owner_id' => $gerritIds]],
                    ['terms' => ['voters' => $gerritIds]],
                ]),
            ]]],
        };
    }

    /**
     * A "filters" aggregation that counts the changes of each step. Without accounts on Gerrit, like for a guest,
     * the filter offers no YOUR_VOTE.
     *
     * @param list<int> $gerritIds the accounts of the user logged in
     *
     * @return array<string, mixed>
     */
    public static function aggregation(array $gerritIds = []): array
    {
        $filters = [];
        foreach (WaitingForEnum::cases() as $step) {
            if (WaitingForEnum::YOUR_VOTE === $step && [] === $gerritIds) {
                continue;
            }
            $filters[$step->label()] = self::changes($step, $gerritIds);
        }

        return ['filters' => ['filters' => $filters]];
    }

    /**
     * The buckets of aggregation() in the order of the steps.
     *
     * @param ReadableCollection<int, Bucket> $facet
     *
     * @return ReadableCollection<int, Bucket>
     */
    public static function facet(ReadableCollection $facet): ReadableCollection
    {
        return FacetSelection::inOrderOfLabels(
            $facet,
            array_map(static fn (WaitingForEnum $step): string => $step->label(), WaitingForEnum::cases()),
        );
    }
}
