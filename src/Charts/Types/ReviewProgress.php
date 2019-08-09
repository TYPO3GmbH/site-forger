<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use App\Charts\Colors;

class ReviewProgress extends ReviewChart
{
    public function getData(): array
    {
        $currentPeriodStart = new \DateTimeImmutable();
        $currentYear = (int)$currentPeriodStart->format('Y');

        $dataSetCurrentYear = $this->getCumulativeTicketsToDate($currentYear, 'current', 'month');
        $dataSetPreviousYear = $this->getCumulativeTicketsToDate($currentYear - 1, 'previous', 'month');
        $dataSetPreviousYear2 = $this->getCumulativeTicketsToDate($currentYear - 2, 'previous2', 'month');

        return [
            'labels' => array_replace_recursive(
                array_keys($dataSetCurrentYear),
                array_keys($dataSetPreviousYear),
                array_keys($dataSetPreviousYear2)
            ),
            'datasets' => [
                [
                    'label' => $currentYear,
                    'data' => array_values($dataSetCurrentYear),
                    'backgroundColor' => Colors::getAsRgba(Colors::ORANGE),
                    'borderColor' => Colors::getAsRgb(Colors::ORANGE),
                ],
                [
                    'label' => $currentYear - 1,
                    'data' => array_values($dataSetPreviousYear),
                    'backgroundColor' => Colors::getAsRgba(Colors::GREY),
                    'borderColor' => Colors::getAsRgb(Colors::GREY),
                ],
                [
                    'label' => $currentYear - 2,
                    'data' => array_values($dataSetPreviousYear2),
                    'backgroundColor' => Colors::getAsRgba(Colors::GREY),
                    'borderColor' => Colors::getAsRgb(Colors::GREY),
                ],
            ],
        ];
    }
}
