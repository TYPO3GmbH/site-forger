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
 * The open issues of a category by tracker, a row of CategoryOverview.
 */
final readonly class CategoryRow
{
    /**
     * @param int                $total  all open issues of the category, also those without a tracker
     * @param array<string, int> $counts by the label of each of CategoryOverview::TRACKERS, 0 for none
     */
    public function __construct(
        public string $name,
        public int $total,
        public array $counts,
        public OtherTrackers $other,
    ) {
    }
}
