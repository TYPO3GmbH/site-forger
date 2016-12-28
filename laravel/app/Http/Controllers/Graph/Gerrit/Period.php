<?php

namespace App\Http\Controllers\Graph\Gerrit;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class Full.
 */
class Period extends AbstractGraph
{
    /**
     * @return int
     *
     * @throws \Elastica\Exception\InvalidException
     */
    protected function getData()
    {
        $this->dateInterval = 'month';

        /** @var \DateTime $currentPeriodStart */
        $currentPeriodStart = new \DateTime();
        $year = (int)$currentPeriodStart->format('Y');


        $rawData = [];
        $this->getCumulativeTicketsToDate($year, 'current', self::DOCUMENT_TYPE_REVIEW);
        $this->getCumulativeTicketsToDate($year-1, 'previous', self::DOCUMENT_TYPE_REVIEW);
        $this->getCumulativeTicketsToDate($year-2, 'previous2', self::DOCUMENT_TYPE_REVIEW);
        foreach ($this->result as $date => $rows) {
            $rawData[] = [
                'date' => $date,
                'previous' => $rows['previous'],
                'previous2' => $rows['previous2'],
                'current' => $rows['current'],
            ];
        }
        $this->chartData = [
            'chartData' => $rawData,
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
