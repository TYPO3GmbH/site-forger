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
 * The open issues of the trackers that are not in CategoryOverview::TRACKERS, a column of CategoryRow.
 */
final readonly class OtherTrackers
{
    /**
     * @param list<string> $labels the trackers, sorted
     */
    public function __construct(
        public int $count = 0,
        public array $labels = [],
    ) {
    }
}
