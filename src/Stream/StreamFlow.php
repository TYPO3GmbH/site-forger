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
 * What came in and what went out of a channel per bucket of time: the issues or changes opened, also again, and
 * those closed. The events of tools count, so the difference is what the open ones changed by.
 */
final readonly class StreamFlow
{
    /**
     * @param list<int> $incoming per bucket
     * @param list<int> $outgoing per bucket
     */
    public function __construct(
        public array $incoming,
        public array $outgoing,
    ) {
    }
}
