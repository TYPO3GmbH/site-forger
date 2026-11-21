<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report;

use App\Enum\ReviewStateEnum;

/**
 * A change of an issue by its number, with its state. The state is null when Forger does not have the change.
 */
final readonly class LinkedChange
{
    public function __construct(
        public int $id,
        public ?ReviewStateEnum $state,
    ) {
    }
}
