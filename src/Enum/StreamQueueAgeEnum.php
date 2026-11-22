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
 * How long an open change has had no activity, in bands: the statistics count the changes that wait for a review
 * per band. Backed: the API returns the value as the key.
 */
enum StreamQueueAgeEnum: string
{
    case WEEK = 'week';
    case MONTH = 'month';
    case QUARTER = 'quarter';
    case YEAR = 'year';
    case LONGER = 'longer';

    public function label(): string
    {
        return match ($this) {
            self::WEEK => 'Under 1 week',
            self::MONTH => '1 to 4 weeks',
            self::QUARTER => '1 to 3 months',
            self::YEAR => '3 to 12 months',
            self::LONGER => 'Over 1 year',
        };
    }

    /**
     * The band as a range of an Elasticsearch date range aggregation over the last activity: "from" included,
     * "to" left out.
     *
     * @return array{key: string, from?: string, to?: string}
     */
    public function range(): array
    {
        return match ($this) {
            self::WEEK => ['key' => $this->value, 'from' => 'now-7d/d'],
            self::MONTH => ['key' => $this->value, 'from' => 'now-30d/d', 'to' => 'now-7d/d'],
            self::QUARTER => ['key' => $this->value, 'from' => 'now-90d/d', 'to' => 'now-30d/d'],
            self::YEAR => ['key' => $this->value, 'from' => 'now-365d/d', 'to' => 'now-90d/d'],
            self::LONGER => ['key' => $this->value, 'to' => 'now-365d/d'],
        };
    }
}
