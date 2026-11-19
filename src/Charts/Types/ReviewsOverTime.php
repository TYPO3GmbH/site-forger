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
use App\Elastic\ElasticUtility;
use Elastica\Index;

/**
 * Generates a charts about open reviews over time.
 * Only data created after Janruary 2014 is taken into consideration since Gerrit has junk data in its database, creating weird charts.
 */
class ReviewsOverTime extends ReviewChart
{
    protected readonly Index $index;

    public function __construct()
    {
        $this->index = ElasticUtility::getIndexByName('review');
        parent::__construct($this->index);
    }

    private const string DATE_FORMAT = 'M Y';
    private const string DATE_AGGREGATION_FORMAT = 'quarter';

    public function getData(): array
    {
        $openedDataSet = $this->getOpenedDataSet();
        $closedDataSet = $this->getClosedDataSet();
        $mergedDataSets = array_replace_recursive($openedDataSet, $closedDataSet);

        $dateToFilter = new \DateTimeImmutable()->sub(new \DateInterval('P3Y'));
        $mergedDataSets = array_filter($mergedDataSets, static function (string $key) use ($dateToFilter) {
            $date = \DateTimeImmutable::createFromFormat(static::DATE_FORMAT, $key);

            return $date > $dateToFilter;
        }, ARRAY_FILTER_USE_KEY);

        $labels = array_keys($mergedDataSets);
        $chartData = array_map(static fn (array $metrics): int => $metrics['open'] - ($metrics['closed'] ?? 0), $mergedDataSets);

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Reviews',
                    'data' => array_values($chartData),
                    'backgroundColor' => Colors::getAsRgba(Colors::BLUE),
                    'borderColor' => Colors::getAsRgb(Colors::BLUE),
                    'borderWidth' => static::BORDER_WIDTH,
                    'pointRadius' => static::POINT_RADIUS,
                ],
            ],
        ];
    }

    private function getOpenedDataSet(): array
    {
        $resultSet = $this->fetchData($this->index, $this->getOpenedQuery([
            'bool' => [
                'must_not' => [
                    ['term' => ['work_in_progress' => true]],
                ],
            ],
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
                    'terms' => ['reviewstatus' => ['MERGED', 'ABANDONED']],
                ],
                'must_not' => [
                    [
                        'term' => ['work_in_progress' => true],
                    ],
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
