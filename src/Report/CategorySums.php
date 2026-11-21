<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report;

/**
 * The sums of the columns of CategoryOverview, its last row.
 */
final readonly class CategorySums
{
    /**
     * @param array<string, int> $counts by the label of each of CategoryOverview::TRACKERS
     * @param int                $other  the issues of the other trackers
     */
    public function __construct(
        public int $total,
        public array $counts,
        public int $other,
    ) {
    }
}
