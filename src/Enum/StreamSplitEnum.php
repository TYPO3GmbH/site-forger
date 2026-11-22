<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

/**
 * How the chart of the activity over time splits the events: all together, by the channel (Forge or Gerrit) or by
 * what happened. Backed: the page takes it as a URL parameter.
 */
enum StreamSplitEnum: string
{
    case TOTAL = 'total';
    case SOURCE = 'source';
    case KIND = 'kind';

    public function label(): string
    {
        return match ($this) {
            self::TOTAL => 'Total',
            self::SOURCE => 'By channel',
            self::KIND => 'By kind',
        };
    }

    /**
     * The field of the stream index the series come from, null for a single series.
     */
    public function field(): ?string
    {
        return match ($this) {
            self::TOTAL => null,
            self::SOURCE => 'source',
            self::KIND => 'kind',
        };
    }
}
