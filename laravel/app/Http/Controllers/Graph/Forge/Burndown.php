<?php

namespace App\Http\Controllers\Graph\Forge;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class Burndown.
 */
class Burndown extends AbstractGraph
{
    /**
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData() : array
    {
        $rawData = [];
        $lastRow = [
            'closed' => 1,
            'open' => 1,
        ];
        $open = $this->getOpenedReviews();
        $close = $this->getClosedReviews();
        $result = array_replace_recursive($open, $close);
        ksort($result);
        foreach ($result as $date => $rows) {
            $rows['closed'] = $rows['closed'] ?? $lastRow['closed'];
            $rows['open'] = $rows['open'] ?? $lastRow['open'];
            $rawData[] = [
                'date' => $date,
                'open' => $rows['open'],
                'closed' => $rows['closed'],
            ];
            $lastRow = $rows;
        }
        foreach ($rawData as $key => $values) {
            $rawData[$key]['diff'] = ($values['open'] - $values['closed']);
            unset(
                $rawData[$key]['open'],
                $rawData[$key]['closed']
            );
        }
        $this->chartData = [
            'chartData' => $rawData,
            'lines' => [
                'diff' => [
                    'color' => '#E69D00',
                    'title' => 'Days',
                ],
            ],
        ];

        return $this->chartData;
    }
}
