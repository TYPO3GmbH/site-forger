<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

/**
 * How long work waits, as the statistics of the activity show it: the median of the events that end a wait, and
 * how many took how long, see StreamWaitBandEnum. Backed: the API returns the value as the key. See
 * StreamInsightsQuery::durations() for which events each one takes.
 */
enum StreamDurationEnum: string
{
    case TIME_TO_RESOLVE = 'time-to-resolve';
    case TIME_TO_FIRST_REVIEW = 'time-to-first-review';
    case TIME_TO_CLOSE = 'time-to-close';

    public function label(): string
    {
        return match ($this) {
            self::TIME_TO_RESOLVE => 'Time to resolve',
            self::TIME_TO_FIRST_REVIEW => 'Time to first review',
            self::TIME_TO_CLOSE => 'Time to close',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TIME_TO_RESOLVE => 'From the report to resolved, without the issues closed or rejected',
            self::TIME_TO_FIRST_REVIEW => 'From the first patch set to the first vote or comment of another person',
            self::TIME_TO_CLOSE => 'From the first patch set until the change was merged or abandoned',
        };
    }

    /**
     * Whether its bands show the kind of event that ended the wait: a change ends merged or abandoned. A wait that
     * one kind of event ends needs no split.
     */
    public function splitsByKind(): bool
    {
        return self::TIME_TO_CLOSE === $this;
    }

    public function better(): StreamBetterEnum
    {
        return StreamBetterEnum::LESS;
    }

    public function source(): StreamSourceEnum
    {
        return self::TIME_TO_RESOLVE === $this ? StreamSourceEnum::FORGE : StreamSourceEnum::GERRIT;
    }
}
