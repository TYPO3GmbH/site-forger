<?php

namespace App\Http\Controllers\Graph\Forge;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class Redmine Preiod Comparison Graph.
 */
class Period extends AbstractGraph
{

    /**
     * @return array
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData() : array
    {
        $this->dateInterval = 'month';

        /** @var \DateTime $currentPeriodStart */
        $currentPeriodStart = new \DateTime();
        $year = (int)$currentPeriodStart->format('Y');

        $this->getCumulativeTicketsToDate($year, 'current');
        $this->getCumulativeTicketsToDate($year-1, 'previous');
        $this->getCumulativeTicketsToDate($year-2, 'previous2');
        $this->chartData = [
            'chartData' => array_values($this->result),
            'lines' => [
                'previous2' => [
                    'color' => '#eeeeee',
                    'title' => '2 years ago',
                ],
                'previous' => [
                    'color' => '#dddddd',
                    'title' => '1 year ago',
                ],
                'current' => [
                    'color' => '#7AB8FF',
                    'title' => 'This year',
                ],
            ],
        ];

        return $this->chartData;
    }
}
