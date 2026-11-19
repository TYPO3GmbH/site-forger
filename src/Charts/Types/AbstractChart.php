<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use App\Elastic\ElasticUtility;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStatusEnum;
use Elastica\Index;
use Elastica\ResultSet;

abstract class AbstractChart
{
    protected const BORDER_WIDTH = 1;
    protected const POINT_RADIUS = 2;

    abstract protected function getDocumentType(): string;

    /**
     * The index the chart reads, given by its document type ("issue" or "review").
     */
    protected function getIndex(): Index
    {
        return ElasticUtility::getIndexByName($this->getDocumentType());
    }

    protected function convertDateToFormat(string $input, string $outputFormat = 'Y-m-d'): string
    {
        $dateTime = new \DateTimeImmutable($input);

        return $dateTime->format($outputFormat);
    }

    protected function fetchData(array $query): ResultSet
    {
        $search = $this->getIndex()->createSearch($query);

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
            'status.name' => IssueStatusEnum::closedLabels(),
        ];
        if ('review' === $this->getDocumentType()) {
            $terms = [
                'reviewstatus' => ReviewStatusEnum::closedValues(),
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
                                    'format' => 'yyyy',
                                ],
                            ],
                        ],
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
                $date = match ($key) {
                    'previous' => str_replace((string) $year, (string) ($year + 1), $this->convertDateToFormat($frame['key_as_string'], 'M')),
                    'previous2' => str_replace((string) $year, (string) ($year + 2), $this->convertDateToFormat($frame['key_as_string'], 'M')),
                    default => $this->convertDateToFormat($frame['key_as_string'], 'M'),
                };
                $result[$date] = $openSum;
            }
        }

        return $result;
    }
}
