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
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class ChartRegistry
{
    public const array CHARTS = [
        'mergeable' => Types\Mergeable::class,
        'open_reviews' => Types\OpenReviews::class,
        'opened_closed' => Types\OpenedClosed::class,
        'tickets_over_time' => Types\TicketsOverTime::class,
        'reviews_over_time' => Types\ReviewsOverTime::class,
        'review_progress' => Types\ReviewProgress::class,
        'ticket_progress' => Types\TicketProgress::class,
        'tracker_types' => Types\TrackerTypes::class,
    ];

    public function __construct(
        #[AutowireLocator(self::CHARTS)]
        private ContainerInterface $charts,
    ) {
    }

    public function get(string $identifier): ChartInterface
    {
        if (!$this->charts->has($identifier)) {
            throw new \InvalidArgumentException('Chart "' . $identifier . '" is unknown', 1565269002);
        }

        return $this->charts->get($identifier);
    }
}
