<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts;

use App\Charts\Types\ChartInterface;

final class ChartRegistry
{
    private const array CHARTS = [
        'mergeable' => Types\Mergeable::class,
        'open_reviews' => Types\OpenReviews::class,
        'opened_closed' => Types\OpenedClosed::class,
        'tickets_over_time' => Types\TicketsOverTime::class,
        'reviews_over_time' => Types\ReviewsOverTime::class,
        'review_progress' => Types\ReviewProgress::class,
        'ticket_progress' => Types\TicketProgress::class,
        'tracker_types' => Types\TrackerTypes::class,
    ];

    public static function getChartInstance(string $identifier): ChartInterface
    {
        $class = self::CHARTS[$identifier] ?? null;
        if (null !== $class) {
            return new $class();
        }

        throw new \InvalidArgumentException('Chart "' . $identifier . '" is unknown', 1565269002);
    }
}
