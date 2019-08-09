<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts;

use App\Charts\Types\ChartInterface;
use App\Charts\Types\Mergeable;
use App\Charts\Types\OpenedClosed;
use App\Charts\Types\OpenReviews;
use App\Charts\Types\TicketsOverTime;
use Elastica\Index;

final class ChartRegistry
{
    private static $charts = [
        'mergeable' => Mergeable::class,
        'open_reviews' => OpenReviews::class,
        'opened_closed' => OpenedClosed::class,
        'tickets_over_time' => TicketsOverTime::class,
    ];

    public static function getChartInstance(string $identifier, Index $index): ChartInterface
    {
        if (isset(static::$charts[$identifier])) {
            return new static::$charts[$identifier]($index);
        }

        throw new \InvalidArgumentException('Chart "' . $identifier . '" is unknown', 1565269002);
    }
}
