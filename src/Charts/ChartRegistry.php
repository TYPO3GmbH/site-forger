<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts;

use App\Charts\Types\ChartInterface;
use Elastica\Index;

final class ChartRegistry
{
    private static $charts = [
        'mergeable' => Types\Mergeable::class,
        'open_reviews' => Types\OpenReviews::class,
        'opened_closed' => Types\OpenedClosed::class,
        'tickets_over_time' => Types\TicketsOverTime::class,
        'reviews_over_time' => Types\ReviewsOverTime::class,
    ];

    public static function getChartInstance(string $identifier, Index $index): ChartInterface
    {
        if (isset(static::$charts[$identifier])) {
            return new static::$charts[$identifier]($index);
        }

        throw new \InvalidArgumentException('Chart "' . $identifier . '" is unknown', 1565269002);
    }
}
