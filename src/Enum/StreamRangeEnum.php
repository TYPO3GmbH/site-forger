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
 * The time windows of the activity stream to pick from. Backed: the page and the API take it as a URL parameter.
 * Each one ends now and starts at midnight in the time zone of the reader.
 */
enum StreamRangeEnum: string
{
    case WEEK = '7d';
    case MONTH = '30d';
    case HALF_YEAR = '6m';
    case YEAR = '1y';

    public function label(): string
    {
        return match ($this) {
            self::WEEK => '7 days',
            self::MONTH => '30 days',
            self::HALF_YEAR => '6 months',
            self::YEAR => '1 year',
        };
    }

    /**
     * The first day of the window, today being the last one.
     */
    public function firstDay(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $first = match ($this) {
            self::WEEK => $now->modify('-6 days'),
            self::MONTH => $now->modify('-29 days'),
            self::HALF_YEAR => $now->modify('-6 months')->modify('+1 day'),
            self::YEAR => $now->modify('-1 year')->modify('+1 day'),
        };

        return $first->setTime(0, 0);
    }
}
