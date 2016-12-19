<?php

namespace App\Http\Controllers\Graph\Gerrit;

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
    protected function getData()
    {
        $rawData = [];
        $final = [];
        $lastRow = [
            'closed' => 1,
            'open' => 1,
        ];
        $open = $this->getOpenedReviewsWithoutWIP();
        $close = $this->getClosedReviewsWithoutWIP();
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
            $reviewCount = ($values['open'] - $values['closed']);
            if ($reviewCount > 0) {
                $final[] = [
                    'date' => $values['date'],
                    'diff' => $reviewCount,
                ];
            }
        }
        $this->chartData = [
            'chartData' => $final,
            'lines' => [
                'diff' => [
                    'color' => '#C2274A',
                    'title' => 'Days',
                ],
            ],
        ];

        return $this->chartData;
    }
}
