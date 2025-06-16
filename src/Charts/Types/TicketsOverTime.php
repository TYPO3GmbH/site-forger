<?php

declare(strict_types=1);

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

class TicketsOverTime extends IssueChart
{
    protected readonly Index $index;

    public function __construct()
    {
        $this->index = ElasticUtility::getIndexByName('issue');
        parent::__construct($this->index);
    }

    private const string DATE_FORMAT = 'M Y';
    private const string DATE_AGGREGATION_FORMAT = 'quarter';

    public function getData(): array
    {
        $openedDataSet = $this->getOpenedDataSet();
        $closedDataSet = $this->getClosedDataSet();
        $mergedDataSets = array_replace_recursive($openedDataSet, $closedDataSet);

        $dateToFilter = (new \DateTimeImmutable())->sub(new \DateInterval('P3Y'));
        $mergedDataSets = array_filter($mergedDataSets, static function (string $key) use ($dateToFilter) {
            $date = \DateTimeImmutable::createFromFormat(static::DATE_FORMAT, $key);

            return $date > $dateToFilter;
        }, ARRAY_FILTER_USE_KEY);

        $labels = array_keys($mergedDataSets);
        $chartData = array_values(array_map(static fn (array $metrics): int => $metrics['open'] - ($metrics['closed'] ?? 0), $mergedDataSets));

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Tickets',
                    'data' => $chartData,
                    'backgroundColor' => Colors::getAsRgba(Colors::ORANGE),
                    'borderColor' => Colors::getAsRgb(Colors::ORANGE),
                    'borderWidth' => static::BORDER_WIDTH,
                    'pointRadius' => static::POINT_RADIUS,
                ],
            ],
        ];
    }

    private function getOpenedDataSet(): array
    {
        $resultSet = $this->fetchData($this->index, $this->getOpenedQuery([
            'match_all' => new \stdClass(),
        ], static::DATE_AGGREGATION_FORMAT));

        $data = $resultSet->getAggregations();
        $openSum = 0;
        $opened = [];
        if (\is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $openSum += $frame['doc_count'];
                $date = $this->convertDateToFormat($frame['key_as_string'], static::DATE_FORMAT);
                $opened[$date]['open'] = $openSum;
            }
        }

        return $opened;
    }

    private function getClosedDataSet(): array
    {
        $resultSet = $this->fetchData($this->index, $this->getClosedQuery([
            'bool' => [
                'must' => [
                    'terms' => ['status.name' => ['Closed', 'Resolved', 'Rejected']],
                ],
            ],
        ], static::DATE_AGGREGATION_FORMAT));

        $data = $resultSet->getAggregations();
        $closeSum = 0;
        $closed = [];
        if (\is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $closeSum += $frame['doc_count'];
                $date = $this->convertDateToFormat($frame['key_as_string'], static::DATE_FORMAT);
                $closed[$date]['closed'] = $closeSum;
            }
        }

        return $closed;
    }
}
