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

        return $this->chartData = array_values($this->result);
    }
}
