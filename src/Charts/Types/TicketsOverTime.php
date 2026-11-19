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
 * The open issues at the end of each of the last three years' months.
 */
class TicketsOverTime extends IssueChart
{
    public function getData(): array
    {
        $series = $this->openOverTime(36);

        return [
            'labels' => $this->labels($series['keys'], 'M Y'),
            'datasets' => [ChartStyle::line('Open issues', $series['values'], ChartStyle::ISSUES, true)],
        ];
    }
}
