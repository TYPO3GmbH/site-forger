<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

/**
 * The issues or changes closed in a year, summed up month by month, so the years can be compared.
 */
final readonly class StreamClosedYear
{
    /**
     * @param list<int|null> $closed the sum up to the end of each month, January first, null for the months to come
     */
    public function __construct(
        public int $year,
        public array $closed,
    ) {
    }
}
