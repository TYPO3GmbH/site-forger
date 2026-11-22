<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api;

use App\Api\Model\ActivityCalendar;
use App\Api\Model\ActivityClosedYear;
use App\Api\Model\ActivityClosedYears;
use App\Api\Model\ActivityCount;
use App\Api\Model\ActivityCrew;
use App\Api\Model\ActivityDuration;
use App\Api\Model\ActivityEvent;
use App\Api\Model\ActivityExchange;
use App\Api\Model\ActivityFigure;
use App\Api\Model\ActivityNewcomer;
use App\Api\Model\ActivityNewcomers;
use App\Api\Model\ActivityOwnWait;
use App\Api\Model\ActivityPage;
use App\Api\Model\ActivityPartner;
use App\Api\Model\ActivityPerson;
use App\Api\Model\ActivitySeries;
use App\Api\Model\ActivityStatistics;
use App\Api\Model\ActivityTrend;
use App\Api\Model\ActivityWaitBand;
use App\Api\Model\ActivityWeekHours;
use App\Api\Model\ActivityWindow;
use App\Document\StreamEvent;
use App\Elastic\Bucket;
use App\Elastic\SearchResult;
use App\Enum\StreamBetterEnum;
use App\Enum\StreamIntervalEnum;
use App\Enum\StreamKindEnum;
use App\Enum\StreamMilestoneEnum;
use App\Enum\StreamQueueAgeEnum;
use App\Enum\StreamSourceEnum;
use App\People\Person;
use App\People\User;
use App\People\UserDirectory;
use App\Search\StreamWindow;
use App\Stream\OwnWait;
use App\Stream\ReviewExchange;
use App\Stream\ReviewPartner;
use App\Stream\StreamClosedYear;
use App\Stream\StreamCrew;
use App\Stream\StreamDuration;
use App\Stream\StreamFigure;
use App\Stream\StreamHeatmap;
use App\Stream\StreamInsights;
use App\Stream\StreamNewcomers;
use App\Stream\StreamSeries;
use App\Stream\StreamTrend;
use App\Stream\StreamWaitBand;
use App\Twig\LinkTwigExtension;
use App\Twig\UserTwigExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The events and the statistics of the activity stream as the API returns them.
 */
final readonly class ActivityMapper
{
    /**
     * The avatars in pixels: twice the size they show, for sharp screens.
     */
    private const int AVATAR_SIZE = 48;

    public function __construct(
        private UrlGeneratorInterface $urls,
        private UserDirectory $users,
    ) {
    }

    /**
     * @param SearchResult<StreamEvent> $events
     * @param iterable<Bucket>          $branches
     */
    public function page(
        SearchResult $events,
        ListParameters $list,
        ActivityFilters $filters,
        iterable $branches,
    ): ActivityPage {
        $counts = [];
        foreach ($branches as $bucket) {
            $counts[] = new ActivityCount($bucket->key, $bucket->key, $bucket->count);
        }

        return new ActivityPage(
            total: $events->total,
            offset: $list->offset,
            limit: $list->limit,
            window: self::window($filters->window),
            person: $this->personOfKey($filters->personKey),
            branches: $counts,
            items: array_values($events->map($this->event(...))->toArray()),
        );
    }

    public function event(StreamEvent $event): ActivityEvent
    {
        $isForge = StreamSourceEnum::FORGE === $event->source;

        return new ActivityEvent(
            id: $event->id,
            source: $event->source->value,
            kind: $event->kind->value,
            number: $event->number,
            title: $event->title,
            date: ApiDate::fromIndex($event->date),
            author: $this->person($event->author),
            bot: $event->bot,
            summary: $event->summary,
            patchSet: $event->patchSet,
            branch: $isForge ? null : $event->branch,
            url: self::sourceUrl($event->source, $event->number),
            forgerUrl: $this->forgerUrl($event->source, $event->number),
        );
    }

    /**
     * The issue on Forge or the change in Gerrit.
     */
    private static function sourceUrl(StreamSourceEnum $source, int $number): string
    {
        $isForge = StreamSourceEnum::FORGE === $source;

        return sprintf($isForge ? LinkTwigExtension::FORGE_ISSUE : LinkTwigExtension::GERRIT_CHANGE, $number);
    }

    /**
     * The issue or the change in Forger.
     */
    private function forgerUrl(StreamSourceEnum $source, int $number): string
    {
        $isForge = StreamSourceEnum::FORGE === $source;

        return $this->urls->generate(
            $isForge ? 'issue_show' : 'change_show',
            $isForge ? ['id' => $number] : ['number' => $number],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    public function statistics(StreamInsights $insights, ActivityFilters $filters): ActivityStatistics
    {
        $sources = array_map(
            static fn (StreamSourceEnum $source): ActivityCount => new ActivityCount(
                $source->value,
                $source->label(),
                $insights->ofSource($source),
            ),
            StreamSourceEnum::cases(),
        );
        $kinds = array_map(
            static fn (StreamKindEnum $kind): ActivityCount => new ActivityCount(
                $kind->value,
                $kind->label(),
                $insights->ofKind($kind),
            ),
            StreamKindEnum::cases(),
        );

        return new ActivityStatistics(
            window: self::window($filters->window),
            person: $this->personOfKey($filters->personKey),
            events: $insights->events,
            people: self::trend($insights->people),
            activeDays: self::trend($insights->activeDays),
            figures: array_map(
                static fn (StreamFigure $figure): ActivityFigure => new ActivityFigure(
                    $figure->figure->value,
                    $figure->figure->label(),
                    $figure->figure->description(),
                    $figure->count,
                    $figure->previous,
                    $figure->values,
                    $figure->figure->source()->value,
                    array_map(static fn (StreamKindEnum $kind): string => $kind->value, $figure->figure->kinds()),
                    $figure->figure->better()->value,
                    $figure->figure->openDelta(),
                ),
                $insights->figures,
            ),
            openIssues: self::trend($insights->openIssues ?? new StreamTrend(0, 0, [], StreamBetterEnum::LESS)),
            openChanges: self::trend($insights->openChanges ?? new StreamTrend(0, 0, [], StreamBetterEnum::LESS)),
            durations: array_map(
                static fn (StreamDuration $duration): ActivityDuration => new ActivityDuration(
                    $duration->duration->value,
                    $duration->duration->label(),
                    $duration->duration->description(),
                    $duration->count,
                    $duration->median,
                    $duration->previous,
                    array_map(
                        static fn (StreamWaitBand $band): ActivityWaitBand => self::waitBand($band, $duration),
                        $duration->bands,
                    ),
                    $duration->duration->source()->value,
                    $duration->duration->better()->value,
                ),
                $insights->durations,
            ),
            reviewQueue: array_map(
                static fn (StreamQueueAgeEnum $age): ActivityCount => new ActivityCount(
                    $age->value,
                    $age->label(),
                    $insights->reviewQueue[$age->value] ?? 0,
                ),
                StreamQueueAgeEnum::cases(),
            ),
            crews: array_map(
                static fn (StreamCrew $crew): ActivityCrew => new ActivityCrew(
                    $crew->crew->value,
                    $crew->crew->label(),
                    $crew->crew->description(),
                    $crew->people(),
                    $crew->carriers(),
                    $crew->previousCarriers(),
                    $crew->carrierShares(),
                    StreamBetterEnum::MORE->value,
                ),
                $insights->crews,
            ),
            sources: $sources,
            kinds: $kinds,
            buckets: $insights->timeline->keys,
            series: array_map(
                static fn (StreamSeries $series): ActivitySeries => new ActivitySeries(
                    $series->key,
                    $series->label,
                    $series->values,
                ),
                $insights->timeline->series,
            ),
        );
    }

    /**
     * @param array{issues: list<StreamClosedYear>, changes: list<StreamClosedYear>} $years
     */
    public static function closedYears(array $years): ActivityClosedYears
    {
        $map = static fn (StreamClosedYear $year): ActivityClosedYear => new ActivityClosedYear(
            $year->year,
            $year->closed,
        );

        return new ActivityClosedYears(array_map($map, $years['issues']), array_map($map, $years['changes']));
    }

    public function exchange(ReviewExchange $exchange, ActivityFilters $filters): ActivityExchange
    {
        return new ActivityExchange(
            window: self::window($filters->window),
            buckets: $exchange->buckets,
            given: self::trend($exchange->given),
            received: self::trend($exchange->received),
            givenReviews: $exchange->givenReviews,
            receivedReviews: $exchange->receivedReviews,
            reviewed: $this->partners($exchange->reviewed),
            reviewers: $this->partners($exchange->reviewers),
            waits: array_map(
                static fn (OwnWait $wait): ActivityOwnWait => new ActivityOwnWait(
                    $wait->duration->value,
                    $wait->duration->label(),
                    $wait->duration->description(),
                    $wait->count,
                    $wait->median,
                    $wait->allMedian,
                ),
                $exchange->waits,
            ),
        );
    }

    /**
     * @param list<ReviewPartner> $partners
     *
     * @return list<ActivityPartner>
     */
    private function partners(array $partners): array
    {
        $mapped = [];
        foreach ($partners as $partner) {
            $person = $this->person($partner->person);
            if (null !== $person) {
                $mapped[] = new ActivityPartner($person, $partner->changes, $partner->reviews);
            }
        }

        return $mapped;
    }

    private static function trend(StreamTrend $trend): ActivityTrend
    {
        return new ActivityTrend($trend->count, $trend->previous, $trend->values, $trend->better->value);
    }

    public function weekHours(StreamHeatmap $heatmap, ActivityFilters $filters): ActivityWeekHours
    {
        return new ActivityWeekHours(
            self::window($filters->window),
            array_map(
                static fn (int $weekday): array => array_map(
                    static fn (int $hour): int => $heatmap->count($weekday, $hour),
                    range(0, 23),
                ),
                array_keys(StreamHeatmap::WEEKDAYS),
            ),
        );
    }

    /**
     * @param list<int> $days the events per day of the window of the filters
     */
    public function calendar(array $days, ActivityFilters $filters): ActivityCalendar
    {
        return new ActivityCalendar(
            self::window($filters->window),
            $filters->window->from->format('Y-m-d'),
            $days,
        );
    }

    public function newcomers(StreamNewcomers $newcomers, ActivityFilters $filters): ActivityNewcomers
    {
        $people = [];
        foreach ($newcomers->people as $newcomer) {
            $person = $this->person($newcomer->person);
            if (null === $person) {
                continue;
            }
            $source = $newcomer->milestone->source();
            $people[] = new ActivityNewcomer(
                person: $person,
                milestone: $newcomer->milestone->value,
                source: $source->value,
                number: $newcomer->number,
                title: $newcomer->title,
                date: (string) ApiDate::fromIndex($newcomer->date),
                branch: $newcomer->branch,
                state: $newcomer->state->value,
                stateLabel: $newcomer->state->label(),
                needsHelp: $newcomer->state->needsHelp(),
                url: self::sourceUrl($source, $newcomer->number),
                forgerUrl: $this->forgerUrl($source, $newcomer->number),
                mergedBy: $this->person($newcomer->mergedBy),
            );
        }

        return new ActivityNewcomers(
            window: self::window($filters->window),
            count: $newcomers->count,
            previous: $newcomers->previous,
            milestones: array_map(
                static fn (StreamMilestoneEnum $milestone): ActivityCount => new ActivityCount(
                    $milestone->value,
                    $milestone->label(),
                    $newcomers->milestones[$milestone->value] ?? 0,
                ),
                StreamMilestoneEnum::cases(),
            ),
            better: StreamBetterEnum::MORE->value,
            people: $people,
        );
    }

    /**
     * A band of a wait, with the kinds of event that ended its waits where the wait splits by them.
     */
    private static function waitBand(StreamWaitBand $band, StreamDuration $duration): ActivityWaitBand
    {
        $source = $duration->duration->source();
        $kinds = [];
        foreach ($duration->duration->splitsByKind() ? $band->kinds : [] as $value => $count) {
            $kind = StreamKindEnum::tryFrom((string) $value);
            if (null !== $kind) {
                $kinds[] = new ActivityCount($kind->value, ucfirst($kind->verb($source)), $count);
            }
        }

        return new ActivityWaitBand($band->band->value, $band->band->label(), $band->count, $kinds);
    }

    public static function window(StreamWindow $window): ActivityWindow
    {
        $utc = new \DateTimeZone('UTC');

        return new ActivityWindow(
            from: $window->from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            until: $window->until->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            open: $window->isOpen,
            days: $window->wholeDays(),
            timeZone: $window->from->getTimezone()->getName(),
            interval: $window->interval(),
            intervals: array_map(
                static fn (StreamIntervalEnum $interval): string => $interval->value,
                $window->intervals(),
            ),
        );
    }

    /**
     * The person without the email: the API never shows one, see AGENTS.md. As every page shows the person: the
     * name, the username and the key of the TYPO3 account, see UserDirectory::shown().
     */
    public function person(?Person $person): ?ActivityPerson
    {
        $shown = $this->users->shown($person);
        if (null === $shown || '' === $shown->name) {
            return null;
        }

        return new ActivityPerson(
            $shown->name,
            $shown->forgeId,
            $shown->gerritId,
            $this->users->keyOfPerson($shown),
            $this->avatar($shown),
            $shown->username,
        );
    }

    private function personOfKey(?string $key): ?ActivityPerson
    {
        if (null === $key) {
            return null;
        }
        $person = $this->users->personOf($key);
        if (null === $person) {
            return null;
        }

        return new ActivityPerson(
            $person->name ?: $key,
            $person->forgeId,
            $person->gerritId,
            $key,
            $this->avatar($person),
            $person->username,
        );
    }

    private function avatar(Person $person): string
    {
        return $this->users->avatarOfPerson($person, self::AVATAR_SIZE)
            ?? User::avatarUrl(UserTwigExtension::PLACEHOLDER, self::AVATAR_SIZE);
    }
}
