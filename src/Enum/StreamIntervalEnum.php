<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

use App\Charts\TimeSeries;

/**
 * The length of a bucket of the statistics over time, as the reader picks it. Backed: the parameter "interval" of
 * the page and of the API takes the value. A window offers those that give a readable chart, see fits().
 */
enum StreamIntervalEnum: string
{
    case HOUR = TimeSeries::HOUR;
    case DAY = TimeSeries::DAY;
    case WEEK = TimeSeries::WEEK;
    case MONTH = TimeSeries::MONTH;

    /**
     * A window longer than these days has too many weeks for a chart: some seven years.
     */
    public const int WEEKS_UP_TO_DAYS = 2600;

    public function label(): string
    {
        return match ($this) {
            self::HOUR => 'Hour',
            self::DAY => 'Day',
            self::WEEK => 'Week',
            self::MONTH => 'Month',
        };
    }

    /**
     * Whether a window of this many days gives a chart with at least two and at most some 370 buckets.
     */
    public function fits(float $days): bool
    {
        return match ($this) {
            self::HOUR => $days <= 7,
            self::DAY => $days > 1 && $days <= 366,
            self::WEEK => $days >= 14 && $days <= self::WEEKS_UP_TO_DAYS,
            self::MONTH => $days >= 60,
        };
    }
}
