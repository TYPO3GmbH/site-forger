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
 * The issues created in a year, a row of MonthOverview.
 */
final readonly class YearRow
{
    /**
     * @param string          $filter the days of the year for the filter "Created", see MonthOverview::yearFilter()
     * @param list<MonthCell> $months all twelve, January first
     */
    public function __construct(
        public string $year,
        public string $filter,
        public int $total,
        public array $months,
    ) {
    }
}
