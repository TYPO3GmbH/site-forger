<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * A column of a sprint board: how many issues it has, and the cards it shows.
 */
final readonly class BoardColumn
{
    /**
     * @param ReadableCollection<int, Card> $cards
     */
    public function __construct(
        public BoardColumnEnum $column,
        public int $count,
        public ReadableCollection $cards = new ArrayCollection(),
    ) {
    }
}
