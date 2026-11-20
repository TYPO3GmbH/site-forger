<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts\Types;

use App\Charts\ChartStyle;
use App\Elastic\Bucket;
use App\Enum\ReviewStatusEnum;

/**
 * The open Gerrit changes waiting for a review, by the time of their last activity:
 * the right end of the chart are the changes nobody looks at.
 */
class OpenReviews extends ReviewChart
{
    /**
     * Label => range of "updated_on", from the most recent activity on.
     */
    private const array AGES = [
        'This week' => ['from' => 'now-7d/d'],
        '1–4 weeks' => ['from' => 'now-30d/d', 'to' => 'now-7d/d'],
        '1–3 months' => ['from' => 'now-90d/d', 'to' => 'now-30d/d'],
        '3–12 months' => ['from' => 'now-365d/d', 'to' => 'now-90d/d'],
        'Over a year' => ['to' => 'now-365d/d'],
    ];

    public function getData(): array
    {
        $ranges = [];
        foreach (self::AGES as $label => $range) {
            $ranges[] = ['key' => $label, ...$range];
        }
        $result = $this->fetchData([
            'query' => ['bool' => [
                'filter' => [...$this->scope(), ['term' => ['reviewstatus' => ReviewStatusEnum::NEW->value]]],
            ]],
            'size' => 0,
            'aggregations' => ['age' => ['date_range' => ['field' => 'updated_on', 'ranges' => $ranges]]],
        ]);
        $counts = Bucket::countsOf($result->getAggregation('age'));

        $values = array_map(static fn (string $label): int => $counts[$label] ?? 0, array_keys(self::AGES));

        return [
            // The count beside the name, so it reads without hovering
            'labels' => array_map(static fn (string $label, int $count): string => $label . ' · ' . $count, array_keys(self::AGES), $values),
            // The table has its own column for the count
            'tableLabels' => array_keys(self::AGES),
            'datasets' => [ChartStyle::bar('Open changes', $values, ChartStyle::CHANGES)],
        ];
    }
}
