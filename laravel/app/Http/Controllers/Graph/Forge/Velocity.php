<?php

namespace App\Http\Controllers\Graph\Forge;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class Velocity.
 */
class Velocity extends AbstractGraph
{
    /**
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData()
    {
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
            $this->chartData[] = [
                'date' => $date,
                'open' => $rows['open'],
                'closed' => $rows['closed'],
            ];
            $lastRow = $rows;
        }

        return $this->chartData;
    }
}
