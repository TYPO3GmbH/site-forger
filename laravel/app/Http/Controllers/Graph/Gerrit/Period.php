<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http\Controllers\Graph\Gerrit;

use App\Http\Controllers\Graph\AbstractGraph;

/**
 * Class Gerrit Period Comparison Graph.
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

        $this->getCumulativeTicketsToDate($year, 'current', self::DOCUMENT_TYPE_REVIEW);
        $this->getCumulativeTicketsToDate($year-1, 'previous', self::DOCUMENT_TYPE_REVIEW);
        $this->getCumulativeTicketsToDate($year-2, 'previous2', self::DOCUMENT_TYPE_REVIEW);

        return $this->chartData = array_values($this->result);
    }
}
