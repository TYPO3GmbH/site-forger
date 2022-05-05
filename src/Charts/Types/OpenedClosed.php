<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use App\Charts\Colors;
use Elastica\Index;
use T3G\ElasticUtility\ElasticUtility;

class OpenedClosed extends IssueChart
{
    /**
     * @var Index
     */
    protected $index;

    public function __construct()
    {
        $this->index = ElasticUtility::getIndexByName('issue');
        parent::__construct($this->index);
    }

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
        $resultSet = $this->fetchData($this->index, $this->getOpenedQuery([
            'bool' => [
                'must_not' => [
                    'range' => ['created_on' => ['lte' => (new \DateTimeImmutable())->sub(new \DateInterval('P1M'))->format('Y/m/d H:i:s')]],
                ],
            ],
        ]));
        $data = $resultSet->getAggregations();
        $chartData = $this->extractDataFromAggregation($data);

        return [
            'label' => 'Opened',
            'data' => $chartData,
            'backgroundColor' => Colors::getAsRgba(Colors::RED),
            'borderColor' => Colors::getAsRgb(Colors::RED),
            'borderWidth' => static::BORDER_WIDTH,
            'pointRadius' => static::POINT_RADIUS,
        ];
    }

    private function getClosedDataSet(): array
    {
        $resultSet = $this->fetchData($this->index, $this->getClosedQuery([
            'bool' => [
                'must' => [
                    'terms' => ['status.name' => ['Closed', 'Rejected', 'Resolved']],
                ],
                'must_not' => [
                    'range' => ['updated_on' => ['lte' => (new \DateTimeImmutable())->sub(new \DateInterval('P1M'))->format('Y/m/d H:i:s')]],
                ],
            ],
        ]));
        $data = $resultSet->getAggregations();
        $chartData = $this->extractDataFromAggregation($data);

        return [
            'label' => 'Closed',
            'data' => $chartData,
            'backgroundColor' => Colors::getAsRgba(Colors::GREEN),
            'borderColor' => Colors::getAsRgb(Colors::GREEN),
            'borderWidth' => static::BORDER_WIDTH,
            'pointRadius' => static::POINT_RADIUS,
        ];
    }

    private function extractDataFromAggregation(array $data): array
    {
        $chartData = [];
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $i => $frame) {
                $date = $this->convertDateToFormat($frame['key_as_string'], 'd.m');
                $chartData[$date] = $frame['doc_count'];
            }
        }

        return $chartData;
    }
}
