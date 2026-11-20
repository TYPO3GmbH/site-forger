<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use Twig\Attribute\AsTwigFunction;

/**
 * The table of "Issues by month": the open issues by the month they were created, so it shows how old the open issues
 * are. A row per year, the newest first, a cell per month. A cell leads to the search with the filter "Created" of
 * its month, see rangeQuery().
 */
final class MonthOverview
{
    /**
     * The steps of the shading of a cell, see shade().
     */
    public const int SHADES = 5;

    /**
     * The field the months count, and the one of the filter "Created" of the search.
     */
    public const string FIELD = 'created_on';

    /**
     * The documents of a date field from and to, both included, see days(): by default the issues created then, the
     * filter "Created". A value is a day "yyyy-mm-dd" or, as in links shared before, a month "yyyy-mm". One left empty
     * leaves its side open, null for none at all. Another format counts as empty.
     *
     * @return array<string, mixed>|null
     */
    public static function rangeQuery(?string $from, ?string $to, string $field = self::FIELD): ?array
    {
        $range = [];
        $start = self::period($from);
        if (null !== $start) {
            $range['gte'] = $start[0]->format('Y/m/d H:i:s');
        }
        $end = self::period($to);
        if (null !== $end) {
            $range['lt'] = $end[1]->format('Y/m/d H:i:s');
        }

        return [] === $range ? null : ['range' => [$field => $range]];
    }

    /**
     * The days of a filter of dates, like "Created", as the datepicker writes them into its one field: "yyyy-mm-dd to yyyy-mm-dd",
     * or one day alone, which is the first and the last day then. Without a value, from and to as they are.
     *
     * @param array<string, mixed> $filter the parameters of the filter: range, or from and to
     *
     * @return array{from: string|null, to: string|null}
     */
    public static function days(array $filter): array
    {
        $range = trim((string) ($filter['range'] ?? ''));
        if ('' === $range) {
            return ['from' => isset($filter['from']) ? (string) $filter['from'] : null, 'to' => isset($filter['to']) ? (string) $filter['to'] : null];
        }
        $days = array_map(trim(...), explode(' to ', $range, 2));

        return ['from' => $days[0], 'to' => $days[1] ?? $days[0]];
    }

    /**
     * The range of a month in the filter "Created": its first and its last day, as the datepicker writes them.
     */
    public static function monthFilter(string $year, string $month): string
    {
        $first = new \DateTimeImmutable(sprintf('%s-%s-01', $year, $month), new \DateTimeZone('UTC'));

        return $first->format('Y-m-d') . ' to ' . $first->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * The range of a year in the filter "Created": its first and its last day, as the datepicker writes them.
     */
    public static function yearFilter(string $year): string
    {
        return sprintf('%s-01-01 to %s-12-31', $year, $year);
    }

    /**
     * The start of a day or a month and the start of the one after it, null for an empty value or another format.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}|null
     */
    private static function period(?string $value): ?array
    {
        $utc = new \DateTimeZone('UTC');
        if (null !== $value && 1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $value)) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
            // Not a day of the calendar, like 2025-02-30
            if (false === $day || $day->format('Y-m-d') !== $value) {
                return null;
            }

            return [$day, $day->modify('+1 day')];
        }
        if (null !== $value && 1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            $month = new \DateTimeImmutable($value . '-01 00:00:00', $utc);

            return [$month, $month->modify('+1 month')];
        }

        return null;
    }

    /**
     * The rows of the table from the aggregation "year" of the request, with a "month" date histogram and a "status"
     * terms aggregation in it. Months without issues count 0. The range of a year or a month in the filter "Created" of
     * the search: its first and its last day, see yearFilter() and monthFilter().
     *
     * @param list<array<string, mixed>> $years the buckets of the aggregation "year", their keys "yyyy/MM/dd ..."
     *
     * @return list<array{year: string, filter: string, total: int, months: list<array{month: string, filter: string, total: int, statuses: array<string, int>}>}>
     */
    public static function rows(array $years): array
    {
        $rows = [];
        foreach ($years as $year) {
            $yearName = explode('/', (string) $year['key_as_string'])[0];
            $months = [];
            for ($number = 1; $number <= 12; ++$number) {
                $months[$number] = ['month' => sprintf('%02d', $number), 'filter' => self::monthFilter($yearName, sprintf('%02d', $number)), 'total' => 0, 'statuses' => []];
            }
            foreach ($year['month']['buckets'] ?? [] as $month) {
                $number = (int) explode('/', (string) $month['key_as_string'])[1];
                $statuses = [];
                foreach ($month['status']['buckets'] ?? [] as $status) {
                    $statuses[(string) $status['key']] = (int) $status['doc_count'];
                }
                $months[$number] = ['month' => sprintf('%02d', $number), 'filter' => $months[$number]['filter'], 'total' => (int) $month['doc_count'], 'statuses' => $statuses];
            }
            $rows[] = ['year' => $yearName, 'filter' => self::yearFilter($yearName), 'total' => (int) $year['doc_count'], 'months' => array_values($months)];
        }
        usort($rows, static fn (array $a, array $b): int => $b['year'] <=> $a['year']);

        return $rows;
    }

    /**
     * The largest count of a month, the darkest shade.
     *
     * @param list<array{year: string, filter: string, total: int, months: list<array{month: string, filter: string, total: int, statuses: array<string, int>}>}> $rows
     */
    public static function maximum(array $rows): int
    {
        $maximum = 0;
        foreach ($rows as $row) {
            foreach ($row['months'] as $month) {
                $maximum = max($maximum, $month['total']);
            }
        }

        return $maximum;
    }

    /**
     * The shade of a cell, 0 for none, else 1 to SHADES. On a logarithmic scale: a few months hold most of the open
     * issues, on a straight one all others would look the same.
     */
    #[AsTwigFunction(name: 'shade')]
    public static function shade(int $count, int $maximum): int
    {
        if ($count <= 0 || $maximum <= 0) {
            return 0;
        }
        if ($maximum <= 1) {
            return self::SHADES;
        }

        return max(1, (int) ceil(log($count + 1) / log($maximum + 1) * self::SHADES));
    }
}
