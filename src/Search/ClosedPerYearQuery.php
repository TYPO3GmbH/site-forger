<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Charts\TimeSeries;
use App\Enum\ReviewStatusEnum;

/**
 * The issues or changes closed in a year, per month. They count by the day they closed: Forge sets "closed_on" when
 * an issue first closes and keeps it when a sweep later moves the issue from resolved to closed. For a change see
 * GerritChangeImporter::closedOn().
 */
final class ClosedPerYearQuery
{
    public const string BY_CLOSE = 'by_close';
    public const string BY_UPDATE = 'by_update';
    public const string MONTHS = 'months';

    private const string CLOSED_ON = 'closed_on';
    private const string UPDATED_ON = 'updated_on';

    /**
     * @return array<string, mixed>
     */
    public static function issues(int $year): array
    {
        return self::request([IssueSearch::closed()], $year);
    }

    /**
     * Without the changes in progress: the open ones are left out everywhere else, too.
     *
     * @return array<string, mixed>
     */
    public static function changes(int $year): array
    {
        return self::request(
            [
                ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]],
                ['bool' => ['must_not' => [['term' => ['work_in_progress' => true]]]]],
            ],
            $year,
        );
    }

    /**
     * @param list<array<string, mixed>> $closed the clauses of a closed document
     *
     * @return array<string, mixed>
     */
    private static function request(array $closed, int $year): array
    {
        // A document of an older import lacks the day of the close, see ImportSchema: its last update stands in
        // until the import loaded the document again
        $withoutClose = ['bool' => [
            'filter' => [self::inYear(self::UPDATED_ON, $year)],
            'must_not' => [['exists' => ['field' => self::CLOSED_ON]]],
        ]];

        return [
            'query' => ['bool' => ['filter' => $closed]],
            'size' => 0,
            'aggs' => [
                self::BY_CLOSE => self::months(self::inYear(self::CLOSED_ON, $year), self::CLOSED_ON, $year),
                self::BY_UPDATE => self::months($withoutClose, self::UPDATED_ON, $year),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return array<string, mixed>
     */
    private static function months(array $filter, string $field, int $year): array
    {
        $start = new \DateTimeImmutable($year . '-01-01');

        return [
            'filter' => $filter,
            'aggs' => [self::MONTHS => TimeSeries::histogram($field, TimeSeries::MONTH, $start)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function inYear(string $field, int $year): array
    {
        return ['range' => [$field => ['gte' => $year, 'lt' => $year + 1, 'format' => 'yyyy']]];
    }
}
