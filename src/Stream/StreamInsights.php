<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Enum\StreamKindEnum;
use App\Enum\StreamSourceEnum;

/**
 * The statistics of the activity stream for a filter: the key figures, the events over time and per weekday and
 * hour. See StreamStatistics.
 */
final readonly class StreamInsights
{
    /**
     * @param array<string, int>   $sources               by the value of StreamSourceEnum
     * @param array<string, int>   $kinds                 by the value of StreamKindEnum
     * @param list<StreamFigure>   $figures               in the order of StreamFigureEnum
     * @param StreamTrend|null     $openIssues            the open issues at the end of the window and of each
     *                                                    bucket, and at its start as "previous"
     * @param StreamTrend|null     $openChanges           the same of the changes
     * @param list<StreamDuration> $durations             in the order of StreamDurationEnum
     * @param list<StreamCrew>     $crews                 in the order of StreamCrewEnum
     * @param array<string, int>   $reviewQueue           the open changes that wait for a review, by the value of
     *                                                    StreamQueueAgeEnum
     * @param StreamTrend|null     $openChangesInProgress the part of the open changes that is work in progress
     */
    public function __construct(
        public int $events,
        public StreamTrend $people,
        public StreamTrend $activeDays,
        public array $sources,
        public array $kinds,
        public StreamTimeline $timeline,
        public array $figures = [],
        public ?StreamTrend $openIssues = null,
        public ?StreamTrend $openChanges = null,
        public array $durations = [],
        public array $crews = [],
        public array $reviewQueue = [],
        public ?StreamTrend $openChangesInProgress = null,
    ) {
    }

    public function ofSource(StreamSourceEnum $source): int
    {
        return $this->sources[$source->value] ?? 0;
    }

    public function ofKind(StreamKindEnum $kind): int
    {
        return $this->kinds[$kind->value] ?? 0;
    }
}
