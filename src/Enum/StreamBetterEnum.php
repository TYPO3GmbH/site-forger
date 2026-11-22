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
 * Which way a number of the statistics gets better: more work done, fewer open issues, shorter waits. The page
 * colors its change by it. Backed: the API returns the value as "better" of a figure.
 */
enum StreamBetterEnum: string
{
    case MORE = 'more';
    case LESS = 'less';
    /**
     * More or less is not good or bad by itself, like the issues reported.
     */
    case NEITHER = 'neither';

    public function label(): string
    {
        return match ($this) {
            self::MORE => 'More is better',
            self::LESS => 'Less is better',
            self::NEITHER => 'Neither better nor worse',
        };
    }
}
