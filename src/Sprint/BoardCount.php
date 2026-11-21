<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

/**
 * How many issues of a sprint board are in each group, see SprintBoards::counts().
 */
final readonly class BoardCount
{
    public int $total;

    /**
     * The share of the closed issues in percent, null for a board without issues.
     */
    public ?float $percentage;

    public function __construct(
        public int $open,
        public int $closed,
        public int $blocked,
        public int $review,
    ) {
        $this->total = $open + $closed + $blocked + $review;
        $this->percentage = $this->total > 0 ? round($closed * 100.0 / $this->total) : null;
    }
}
