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
}
