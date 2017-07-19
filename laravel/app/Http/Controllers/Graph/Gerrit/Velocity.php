<?php

namespace App\Http\Controllers\Graph\Gerrit;

use App\Elastic\Connection;
use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class Velocity
 */
class Velocity extends AbstractGraph
{
    /**
     * Velocity constructor.
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        parent::__construct($connection);
        $this->dateInterval = 'month';
    }

    /**
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData() : array
    {
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
