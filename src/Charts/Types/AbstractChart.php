<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use App\Charts\TimeSeries;
use App\Elastic\ElasticUtility;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStatusEnum;
use Elastica\Index;
use Elastica\ResultSet;

abstract class AbstractChart
{
    abstract protected function getDocumentType(): string;

    /**
     * The index the chart reads, given by its document type ("issue" or "review").
     */
    protected function getIndex(): Index
    {
        return ElasticUtility::getIndexByName($this->getDocumentType());
    }

    protected function fetchData(array $query): ResultSet
    {
        $search = $this->getIndex()->createSearch($query);

        return $search->search();
    }

    /**
     * The documents the chart counts at all, e.g. no Gerrit changes in progress. Overridden by the document types.
     *
     * @return list<array<string, mixed>> clauses that have to match
     */
    protected function scope(): array
    {
        return [];
    }

    /**
     * The clause of closed documents: resolved, closed or rejected issues, merged or abandoned changes.
     *
     * @return array<string, mixed>
     */
    protected function closed(): array
    {
        return 'review' === $this->getDocumentType()
            ? ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]]
            : ['terms' => ['status.name' => IssueStatusEnum::closedLabels()]];
    }

    /**
     * The documents per interval of a date field, keyed like TimeSeries::keys().
     *
     * @param list<array<string, mixed>> $filter
     *
     * @return array<string, int>
     */
    protected function countsOverTime(array $filter, string $field, string $interval, ?\DateTimeImmutable $from = null): array
    {
        $result = $this->fetchData([
            'query' => ['bool' => ['filter' => [...$this->scope(), ...$filter]]],
            'size' => 0,
            'aggregations' => ['over_time' => TimeSeries::histogram($field, $interval, $from)],
        ]);

        return TimeSeries::counts($result->getAggregation('over_time'));
    }

    /**
     * How many documents were open at the end of each of the last months.
     * Closed documents count as closed when they were updated the last time.
     *
     * @return array{keys: list<string>, values: list<int>}
     */
    protected function openOverTime(int $months): array
    {
        $now = new \DateTimeImmutable();
        $keys = TimeSeries::keys($now->modify('-' . ($months - 1) . ' months'), $now, TimeSeries::MONTH);
        // The whole history, the level of the first month depends on everything before it
        $opened = $this->countsOverTime([], 'created_on', TimeSeries::MONTH);
        $closed = $this->countsOverTime([$this->closed()], 'updated_on', TimeSeries::MONTH);

        return ['keys' => $keys, 'values' => TimeSeries::openAtEnd($opened, $closed, $keys)];
    }

    /**
     * The documents closed in a year up to each month, null for the months still to come.
     *
     * @return list<int|null>
     */
    protected function closedPerYear(int $year): array
    {
        $start = new \DateTimeImmutable($year . '-01-01');
        $keys = TimeSeries::keys($start, $start->modify('last day of december'), TimeSeries::MONTH);
        $counts = $this->countsOverTime(
            [$this->closed(), ['range' => ['updated_on' => ['gte' => $year, 'lt' => $year + 1, 'format' => 'yyyy']]]],
            'updated_on',
            TimeSeries::MONTH,
            $start,
        );
        $values = TimeSeries::cumulative(TimeSeries::align($counts, $keys));
        $currentMonth = (new \DateTimeImmutable())->format('Y-m');

        return array_map(
            static fn (string $key, int $value): ?int => strcmp($key, $currentMonth) > 0 ? null : $value,
            $keys,
            $values,
        );
    }

    /**
     * Readable labels of the keys, e.g. "Nov 2026" for a month or "16 Nov" for a week.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    protected function labels(array $keys, string $format): array
    {
        return array_map(static fn (string $key): string => (new \DateTimeImmutable(7 === strlen($key) ? $key . '-01' : $key))->format($format), $keys);
    }
}
