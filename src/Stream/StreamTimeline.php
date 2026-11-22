<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

/**
 * The events of the window per hour, day or week, as series, for the statistics of the API.
 */
final readonly class StreamTimeline
{
    /**
     * @param string             $interval see App\Charts\TimeSeries
     * @param list<string>       $keys     one per bucket, see TimeSeries::keys()
     * @param list<StreamSeries> $series   in their fixed order
     */
    public function __construct(
        public string $interval,
        public array $keys,
        public array $series,
    ) {
    }
}
