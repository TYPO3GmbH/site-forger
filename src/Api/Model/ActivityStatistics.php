<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api\Model;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'Statistics of the activity: the key figures, the events over time, per weekday and hour.')]
final readonly class ActivityStatistics
{
    /**
     * @param list<ActivityFigure>   $figures
     * @param list<ActivityFlow>     $flows
     * @param list<ActivityDuration> $durations
     * @param list<ActivityCount>    $reviewQueue
     * @param list<ActivityCrew>     $crews
     * @param list<ActivityCount>    $sources
     * @param list<ActivityCount>    $kinds
     * @param list<string>           $buckets
     * @param list<ActivitySeries>   $series
     */
    public function __construct(
        #[OA\Property(
            description: 'The time the events are from.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityWindow::class))],
        )]
        public ActivityWindow $window,
        #[OA\Property(
            description: 'The person of the parameter "person", null without it.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityPerson::class))],
            nullable: true,
        )]
        public ?ActivityPerson $person,
        #[OA\Property(description: 'How many events the filter finds.', example: 7194)]
        public int $events,
        #[OA\Property(
            description: 'How many people did something, about: counted by name.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityTrend::class))],
        )]
        public ActivityTrend $people,
        #[OA\Property(
            description: 'On how many days of the time zone something happened. Per bucket: the days of a week, '
                . 'one for a day or an hour with events.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityTrend::class))],
        )]
        public ActivityTrend $activeDays,
        #[OA\Property(
            description: 'The key figures: what the people got done, in a fixed order.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityFigure::class)),
        )]
        public array $figures,
        #[OA\Property(
            description: 'The open issues at the end of the window and of each bucket, "previous" at its start. '
                . 'Counted back from the open ones now: it does not depend on the kinds and the person.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityTrend::class))],
        )]
        public ActivityTrend $openIssues,
        #[OA\Property(
            description: 'The open changes, as "openIssues" counts the issues. The branch counts.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityTrend::class))],
        )]
        public ActivityTrend $openChanges,
        #[OA\Property(
            description: 'The part of "openChanges" that is work in progress, counted the same way.',
            allOf: [new OA\Schema(ref: new Model(type: ActivityTrend::class))],
        )]
        public ActivityTrend $openChangesInProgress,
        #[OA\Property(
            description: 'What came in and what went out per channel, Forge first. Over the window the difference is '
                . 'what the open ones changed by.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityFlow::class)),
        )]
        public array $flows,
        #[OA\Property(
            description: 'How long work waited, in a fixed order. It does not depend on the kinds and the person.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityDuration::class)),
        )]
        public array $durations,
        #[OA\Property(
            description: 'The open changes that wait for a review now, by the time since their last activity, the '
                . 'shortest first. Without work in progress. The branch counts, the time window does not.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityCount::class)),
        )]
        public array $reviewQueue,
        #[OA\Property(
            description: 'The people who carry the review, in a fixed order. They count in Gerrit only.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityCrew::class)),
        )]
        public array $crews,
        #[OA\Property(
            description: 'The events per channel, all channels.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityCount::class)),
        )]
        public array $sources,
        #[OA\Property(
            description: 'The events per kind, all kinds.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivityCount::class)),
        )]
        public array $kinds,
        #[OA\Property(
            description: 'The start of each bucket of time in the time zone: "2026-11-16T14" for an hour, '
                . '"2026-11-16" for a day, the Monday for a week.',
            type: 'array',
            items: new OA\Items(type: 'string'),
        )]
        public array $buckets,
        #[OA\Property(
            description: 'The events per bucket, one series for all, or one per channel or kind, see the parameter '
                . '"split". In a fixed order, also those without events.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ActivitySeries::class)),
        )]
        public array $series,
    ) {
    }
}
