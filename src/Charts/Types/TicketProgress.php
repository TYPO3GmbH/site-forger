<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use App\Charts\ChartStyle;

/**
 * The issues closed in this and the two years before, summed up month by month, so the years can be compared.
 */
class TicketProgress extends IssueChart
{
    public function getData(): array
    {
        $year = (int) (new \DateTimeImmutable())->format('Y');
        $datasets = [];
        foreach (ChartStyle::YEARS as $offset => $color) {
            $datasets[] = ChartStyle::line((string) ($year - $offset), $this->closedPerYear($year - $offset), $color);
        }

        return [
            'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'datasets' => $datasets,
        ];
    }
}
