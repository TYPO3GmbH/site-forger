<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Enum\StreamKindEnum;
use App\Enum\StreamWaitBandEnum;

/**
 * How many waits of the window took as long as the band, and by which kind of event they ended: a change merged
 * or abandoned.
 */
final readonly class StreamWaitBand
{
    /**
     * @param array<string, int> $kinds the count by the value of StreamKindEnum
     */
    public function __construct(
        public StreamWaitBandEnum $band,
        public int $count,
        public array $kinds,
    ) {
    }

    public function ofKind(StreamKindEnum $kind): int
    {
        return $this->kinds[$kind->value] ?? 0;
    }
}
