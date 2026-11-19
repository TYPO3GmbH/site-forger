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
use App\Charts\TimeSeries;

/**
 * The issues opened and closed in each of the last twelve weeks.
 */
class OpenedClosed extends IssueChart
{
    private const int WEEKS = 12;

    public function getData(): array
    {
        $now = new \DateTimeImmutable();
        $from = $now->modify('-' . (self::WEEKS - 1) . ' weeks')->modify('monday this week')->setTime(0, 0);
        $keys = TimeSeries::keys($from, $now, TimeSeries::WEEK);
        $opened = $this->countsOverTime([$this->since('created_on', $from)], 'created_on', TimeSeries::WEEK, $from);
        $closed = $this->countsOverTime([$this->closed(), $this->since('updated_on', $from)], 'updated_on', TimeSeries::WEEK, $from);

        return [
            'labels' => $this->labels($keys, 'j M'),
            'datasets' => [
                ChartStyle::bar('Opened', TimeSeries::align($opened, $keys), ChartStyle::ISSUES),
                ChartStyle::bar('Closed', TimeSeries::align($closed, $keys), ChartStyle::CLOSED),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function since(string $field, \DateTimeImmutable $from): array
    {
        return ['range' => [$field => ['gte' => $from->format('Y/m/d H:i:s')]]];
    }
}
