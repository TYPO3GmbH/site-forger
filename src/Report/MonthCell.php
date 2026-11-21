<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report;

/**
 * The issues created in a month, for a cell of MonthOverview.
 */
final readonly class MonthCell
{
    /**
     * @param string             $month    the number of the month, "01" to "12"
     * @param string             $filter   the days of the month for the filter "Created", see MonthOverview::monthFilter()
     * @param array<string, int> $statuses how many of the issues have each status, by its name
     */
    public function __construct(
        public string $month,
        public string $filter,
        public int $total = 0,
        public array $statuses = [],
    ) {
    }
}
