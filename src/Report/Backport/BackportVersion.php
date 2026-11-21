<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report\Backport;

use App\Document\Change;
use App\Enum\ReviewStateEnum;

/**
 * A version a change in main targets, with its backport in Gerrit. Without a backport the version is missing.
 */
final readonly class BackportVersion
{
    public ?ReviewStateEnum $state;

    public function __construct(
        public string $version,
        public ?Change $change,
    ) {
        $this->state = $change?->state();
    }

    public function isMissing(): bool
    {
        return null === $this->change;
    }
}
