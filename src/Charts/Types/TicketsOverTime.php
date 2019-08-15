<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use App\Charts\Colors;

class TicketsOverTime extends IssueChart
{
    public function getData(): array
    {
        $openedDataSet = $this->getOpenedDataSet();
        $closedDataSet = $this->getClosedDataSet();
        $mergedDataSets = array_replace_recursive($openedDataSet, $closedDataSet);

        $labels = array_keys($mergedDataSets);
        $chartData = array_values(array_map(static function (array $metrics): int {
            return $metrics['open'] - ($metrics['closed'] ?? 0);
        }, $mergedDataSets));

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Tickets',
                    'data' => $chartData,
                    'backgroundColor' => Colors::getAsRgba(Colors::ORANGE),
                    'borderColor' => Colors::getAsRgb(Colors::ORANGE),
                    'borderWidth' => static::BORDER_WIDTH,
                    'pointRadius' => static::POINT_RADIUS,
                ],
            ],
        ];
    }

    private function getOpenedDataSet(): array
    {
        $resultSet = $this->fetchData($this->getOpenedQuery([
            'match_all' => new \stdClass(),
        ], 'quarter'));

        $data = $resultSet->getAggregations();
        $openSum = 0;
        $opened = [];
        if (\is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $openSum += $frame['doc_count'];
                $date = $this->convertDateToFormat($frame['key_as_string'], 'M Y');
                $opened[$date]['open'] = $openSum;
            }
        }

        return $opened;
    }

    private function getClosedDataSet(): array
    {
        $resultSet = $this->fetchData($this->getClosedQuery([
            'bool' => [
                'must' => [
                    'terms' => ['status.name' => ['Closed', 'Resolved', 'Rejected']],
                ],
            ],
        ], 'quarter'));

        $data = $resultSet->getAggregations();
        $closeSum = 0;
        $closed = [];
        if (\is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $closeSum += $frame['doc_count'];
                $date = $this->convertDateToFormat($frame['key_as_string'], 'M Y');
                $closed[$date]['closed'] = $closeSum;
            }
        }

        return $closed;
    }
}
