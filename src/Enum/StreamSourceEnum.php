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
 * Where an event of the activity stream happened. Backed: the stream index stores it, and the filter of the page
 * and of the API takes it.
 */
enum StreamSourceEnum: string
{
    case FORGE = 'forge';
    case GERRIT = 'gerrit';

    public function label(): string
    {
        return match ($this) {
            self::FORGE => 'Forge',
            self::GERRIT => 'Gerrit',
        };
    }

    /**
     * The key of its color: the custom property "--stream-<key>" of assets/css/module/_stream.scss.
     */
    public function colorKey(): string
    {
        return $this->value;
    }
}
