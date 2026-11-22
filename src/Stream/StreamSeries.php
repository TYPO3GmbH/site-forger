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
 * One series of the activity over time: a channel, a kind, or all events. Its color on the page is the custom
 * property "--stream-<key>" of assets/css/module/_stream.scss.
 */
final readonly class StreamSeries
{
    /**
     * @param string    $key    like "forge" or "kind-voted"
     * @param list<int> $values one per bar
     */
    public function __construct(
        public string $label,
        public string $key,
        public array $values,
    ) {
    }
}
