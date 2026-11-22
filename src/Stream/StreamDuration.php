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
 * How long work waited in the window: the median in seconds, in the window before, and how many waits took how
 * long. Null where nothing ended a wait.
 */
final readonly class StreamDuration
{
    /**
     * @param list<StreamWaitBand> $bands one per StreamWaitBandEnum, the shortest first
     */
    public function __construct(
        public StreamDurationEnum $duration,
        public int $count,
        public ?int $median,
        public ?int $previous,
        public array $bands,
    ) {
    }
}
