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
 * How long a wait took, in bands: the statistics count the waits of a window per band, so a single long wait is one
 * of many and does not distort the picture. Backed: the API returns the value as the key.
 */
enum StreamWaitBandEnum: string
{
    case HOUR = 'hour';
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';
    case LONGER = 'longer';

    private const int HOUR_SECONDS = 3600;
    private const int DAY_SECONDS = 86400;

    public function label(): string
    {
        return match ($this) {
            self::HOUR => 'Under 1 hour',
            self::DAY => '1 hour to 1 day',
            self::WEEK => '1 to 7 days',
            self::MONTH => '1 to 4 weeks',
            self::LONGER => 'Over 4 weeks',
        };
    }

    /**
     * The band as a range of an Elasticsearch range aggregation: "from" included, "to" left out, in seconds.
     *
     * @return array{key: string, from?: int, to?: int}
     */
    public function range(): array
    {
        return match ($this) {
            self::HOUR => ['key' => $this->value, 'to' => self::HOUR_SECONDS],
            self::DAY => ['key' => $this->value, 'from' => self::HOUR_SECONDS, 'to' => self::DAY_SECONDS],
            self::WEEK => ['key' => $this->value, 'from' => self::DAY_SECONDS, 'to' => 7 * self::DAY_SECONDS],
            self::MONTH => ['key' => $this->value, 'from' => 7 * self::DAY_SECONDS, 'to' => 28 * self::DAY_SECONDS],
            self::LONGER => ['key' => $this->value, 'from' => 28 * self::DAY_SECONDS],
        };
    }
}
