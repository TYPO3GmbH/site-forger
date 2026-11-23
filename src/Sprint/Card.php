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
use App\Enum\ReviewStatusEnum;

/**
 * An issue on a sprint board, in its column, with its Gerrit change if it has one: the open change that resolves
 * it, or the one an issue under review links.
 */
final readonly class Card
{
    public function __construct(
        public Issue $issue,
        public BoardColumnEnum $column,
        public ?Change $change = null,
    ) {
    }

    /**
     * What the change still needs, as a column of a board of reviews names it, see ReviewBoards::GROUPS. Null
     * without a change, and for a change that is merged or abandoned.
     */
    public function reviewGroup(): ?string
    {
        if (null === $this->change || ReviewStatusEnum::NEW !== $this->change->state()->status()) {
            return null;
        }

        return ReviewBoards::group($this->change);
    }
}
