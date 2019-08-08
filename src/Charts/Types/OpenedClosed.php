<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

class OpenedClosed extends IssueChart
{
    public function getData(): array
    {
        $openedDataSet = $this->getOpenedDataSet();
        $closedDataSet = $this->getClosedDataSet();

        $labels = array_keys($openedDataSet['data']);
        $openedDataSet['data'] = array_values($openedDataSet['data']);
        $closedDataSet['data'] = array_values($closedDataSet['data']);

        return [
            'labels' => $labels,
            'datasets' => [$openedDataSet, $closedDataSet],
        ];
    }

    private function getOpenedDataSet(): array
    {
        $data = $this->fetchData($this->getOpenedQuery());
        $chartData = [];
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $i => $frame) {
                $date = $this->convertDateToFormat($frame['key_as_string'], 'W Y');
                $chartData[$date] = $frame['doc_count'];
            }
        }

        return [
            'label' => 'Opened',
            'backgroundColor' => 'rgba(255, 99, 132, 0.6)',
            'borderColor' => 'rgb(255, 99, 132)',
            'data' => $chartData,
        ];
    }

    private function getClosedDataSet(): array
    {
        $data = $this->fetchData($this->getClosedQuery());
        $chartData = [];
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $i => $frame) {
                $date = $this->convertDateToFormat($frame['key_as_string'], 'W Y');
                $chartData[$date] = $frame['doc_count'];
            }
        }

        return [
            'label' => 'Closed',
            'backgroundColor' => 'rgba(75, 192, 192, 0.6)',
            'borderColor' => 'rgb(75, 192, 192)',
            'data' => $chartData,
        ];
    }

    private function fetchData(array $query): array
    {
        $search = $this->index->createSearch($query);
        $search->addType($this->getDocumentType());
        $resultSet = $search->search();

        return $resultSet->getAggregations();
    }
}
