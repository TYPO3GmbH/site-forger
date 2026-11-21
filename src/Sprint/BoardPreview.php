<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use Doctrine\Common\Collections\ReadableCollection;

/**
 * The issues a filter of the board wizard selects: see SprintBoards::preview().
 */
final readonly class BoardPreview
{
    /**
     * @param list<BoardColumn>             $columns how many issues each column has
     * @param int                           $total   all issues of the board
     * @param ReadableCollection<int, Card> $cards   the issues updated last
     */
    public function __construct(
        public array $columns,
        public int $total,
        public ReadableCollection $cards,
    ) {
    }
}
