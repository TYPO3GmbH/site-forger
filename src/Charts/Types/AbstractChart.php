<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use Elastica\Index;
use Elastica\ResultSet;

abstract class AbstractChart
{
    /**
     * @var Index
     */
    protected $index;

    abstract protected function getDocumentType(): string;

    public function __construct(Index $index)
    {
        $this->index = $index;
    }

    protected function convertDateToFormat(string $input, string $outputFormat = 'Y-m-d'): string
    {
        $dateTime = new \DateTimeImmutable($input);
        return $dateTime->format($outputFormat);
    }

    protected function fetchData(array $query): ResultSet
    {
        $search = $this->index->createSearch($query);
        $search->addType($this->getDocumentType());
        return $search->search();
    }

    protected function getOpenedQuery(array $query, string $interval = 'day'): array
    {
        return [
            'query' => $query,
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'created_on',
                        'interval' => $interval,
                    ],
                ],
            ],
        ];
    }

    protected function getClosedQuery(array $query, string $interval = 'day'): array
    {
        return [
            'query' => $query,
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'updated_on',
                        'interval' => $interval,
                    ],
                ],
            ],
        ];
    }

    protected function getCumulativeTicketsToDate(int $year, string $key, string $interval = 'day'): array
    {
        $terms = [
            'status.name' => [
                'Closed',
                'Rejected',
                'Resolved',
            ],
        ];
        if ($this->getDocumentType() === 'review') {
            $terms = [
                'reviewstatus' => [
                    'ABANDONED',
                    'MERGED',
                ],
            ];
        }
        $query = [
            'query' => [
                'bool' => [
                    'must' => [
                        ['terms' => $terms],
                        [
                            'range' => [
                                'updated_on' => [
                                    'gte' => $year,
                                    'lt' => $year + 1,
                                    'format' => 'yyyy'
                                ],
                            ],
                        ]
                    ],
                ],
            ],
            'size' => 0,
            'aggregations' => [
                'over_time' => [
                    'date_histogram' => [
                        'field' => 'updated_on',
                        'interval' => $interval,
                    ],
                ],
            ],
        ];
        $resultSet = $this->fetchData($query);
        $data = $resultSet->getAggregations();
        $openSum = 0;
        $result = [];
        if (is_array($data['over_time']['buckets'])) {
            foreach ($data['over_time']['buckets'] as $frame) {
                $openSum += $frame['doc_count'];
                switch ($key) {
                    case 'previous':
                        $date = str_replace($year, $year + 1, $this->convertDateToFormat($frame['key_as_string'], 'M'));
                        break;
                    case 'previous2':
                        $date = str_replace($year, $year + 2, $this->convertDateToFormat($frame['key_as_string'], 'M'));
                        break;
                    default:
                        $date = $this->convertDateToFormat($frame['key_as_string'], 'M');
                }
                $result[$date] = $openSum;
            }
        }

        return $result;
    }
}
