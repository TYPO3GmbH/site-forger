<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Enum\StreamBetterEnum;

/**
 * A number of the statistics with its course: in the window, in the window before, and per bucket of time.
 */
final readonly class StreamTrend
{
    /**
     * @param list<int> $values one per bucket of the timeline
     */
    public function __construct(
        public int $count,
        public int $previous,
        public array $values,
        public StreamBetterEnum $better,
    ) {
    }
}
