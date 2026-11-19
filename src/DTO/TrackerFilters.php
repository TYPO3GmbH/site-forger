<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\DTO;

use App\Enum\TrackerEnum;
use Symfony\Component\HttpFoundation\Request;

/**
 * The trackers left out in the issue manager, from "trackerFilters[<severity>]" of the request.
 */
readonly class TrackerFilters
{
    /**
     * @var list<TrackerEnum>
     */
    public array $excluded;

    public function __construct(Request $request)
    {
        $trackerFilters = $request->query->all('trackerFilters');

        $this->excluded = array_values(array_filter(
            TrackerEnum::filterable(),
            static fn (TrackerEnum $tracker): bool => isset($trackerFilters[$tracker->severity()]),
        ));
    }

    public function isExcluded(TrackerEnum $tracker): bool
    {
        return in_array($tracker, $this->excluded, true);
    }

    /**
     * The filterable trackers that are not left out.
     *
     * @return list<TrackerEnum>
     */
    public function included(): array
    {
        return array_values(array_filter(TrackerEnum::filterable(), fn (TrackerEnum $tracker): bool => !$this->isExcluded($tracker)));
    }
}
