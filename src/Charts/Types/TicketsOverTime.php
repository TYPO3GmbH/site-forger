<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use Elastica\Index;

class TicketsOverTime extends IssueChart
{
    public function getData(): array
    {
        $openedDataSet = $this->getOpenedDataSet();
        $search = $this->index->createSearch($this->getFullRequest());
        $search->addType($this->getDocumentType());
        $resultSet = $search->search();
        $data = $resultSet->getAggregations();

        $labels = [];
        $chartData = [];

        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $i => $frame) {
                $date = $this->convertDateToFormat($frame['key_as_string'], 'M Y');
                $labels[] = $date;
                $chartData[] = $frame['doc_count'];
            }
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Reviews',
                    'data' => $chartData,
                    'backgroundColor' => 'rgba(54, 162, 235, 0.6)',
                    'borderColor' => 'rgb(54, 162, 235)',
                ],
            ],
        ];
    }

    private function fetchData(Index $index, array $query): array
    {
        $search = $index->createSearch($query);
        $search->addType($this->getDocumentType());
        $resultSet = $search->search();

        return $resultSet->getAggregations();
    }
}
