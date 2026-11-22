<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Enum\StreamDurationEnum;

/**
 * How long the changes of a person waited, beside all changes: the medians in seconds of the waits the window
 * ended, null without one. See ReviewExchange.
 */
final readonly class OwnWait
{
    public function __construct(
        public StreamDurationEnum $duration,
        public int $count,
        public ?int $median,
        public ?int $allMedian,
    ) {
    }
}
