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
use App\Enum\ReviewStatusEnum;

class OpenReviews extends ReviewChart
{
    public function getData(): array
    {
        $search = $this->getIndex()->createSearch($this->getFullRequest());
        $resultSet = $search->search();
        $data = $resultSet->getAggregations();

        $labels = [];
        $chartData = [];

        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $i => $frame) {
                $date = $this->convertDateToFormat($frame['key_as_string'], 'Y [W]');
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
                    'backgroundColor' => Colors::getAsRgba(Colors::BLUE),
                    'borderColor' => Colors::getAsRgb(Colors::BLUE),
                    'borderWidth' => static::BORDER_WIDTH,
                    'pointRadius' => static::POINT_RADIUS,
                ],
            ],
        ];
    }

    private function getFullRequest(): array
    {
        return [
            'query' => [
                'bool' => [
                    'must_not' => [
                        ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]],
                        ['term' => ['work_in_progress' => true]],
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'updated_on',
                        'interval' => 'week',
                    ],
                ],
            ],
        ];
    }
}
