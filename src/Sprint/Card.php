<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Document\Change;
use App\Document\Issue;

/**
 * An issue on a sprint board, in its column. An issue under review can show its Gerrit change.
 */
final readonly class Card
{
    public function __construct(
        public Issue $issue,
        public BoardColumnEnum $column,
        public ?Change $change = null,
    ) {
    }
}
