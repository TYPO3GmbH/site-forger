<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report;

use App\Document\DuplicatePair;
use App\Document\Issue;

/**
 * A row of the list of possible duplicates: the pair and its two issues. The newer one is the likely duplicate.
 */
final readonly class DuplicatePairRow
{
    public function __construct(
        public DuplicatePair $pair,
        public Issue $newer,
        public Issue $older,
    ) {
    }
}
