<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

abstract class IssueChart extends AbstractChart implements ChartInterface
{
    public function getDocumentType(): string
    {
        return 'issue';
    }

    protected function getOpenedQuery(string $range = 'P60D'): array
    {
        return [
            'query' => [
                'bool' => [
                    'must_not' => [
                        'range' => ['created_on' => ['lte' => (new \DateTimeImmutable())->sub(new \DateInterval($range))->format('Y/m/d H:i:s')]],
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'created_on',
                        'interval' => 'week',
                    ],
                ],
            ],
        ];
    }

    protected function getClosedQuery(string $range = 'P60D'): array
    {
        return [
            'query' => [
                'bool' => [
                    'must' => [
                        'terms' => ['status.name' => ['Closed', 'Rejected', 'Resolved']],
                    ],
                    'must_not' => [
                        'range' => ['updated_on' => ['lte' => (new \DateTimeImmutable())->sub(new \DateInterval($range))->format('Y/m/d H:i:s')]],
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
