<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\Enum\SystemCheckEnum;

/**
 * Where the comparison of Gerrit with the index stands, see ChangeSweep: the page the next run compares, what this
 * round found so far, and what the last complete round found. A round goes from the newest change to the oldest.
 */
final readonly class ChangeSweepRound
{
    /**
     * @param \DateTimeImmutable|null $until        where the next page starts, null at the newest change
     * @param \DateTimeImmutable|null $started      when this round compared its first page
     * @param int                     $added        the changes this round found missing in the index
     * @param int                     $removed      the changes this round removed: Gerrit does not show them
     * @param \DateTimeImmutable|null $lastStarted  when the last complete round compared its first page
     * @param \DateTimeImmutable|null $lastFinished when the last complete round ended, null before the first one
     * @param int                     $lastAdded    the changes the last complete round found missing
     * @param int                     $lastRemoved  the changes the last complete round removed
     */
    public function __construct(
        public ?\DateTimeImmutable $until = null,
        public ?\DateTimeImmutable $started = null,
        public int $added = 0,
        public int $removed = 0,
        public ?\DateTimeImmutable $lastStarted = null,
        public ?\DateTimeImmutable $lastFinished = null,
        public int $lastAdded = 0,
        public int $lastRemoved = 0,
    ) {
    }

    /**
     * The round after a run compared a page.
     *
     * @param \DateTimeImmutable|null $next where the next page starts, null after the last page
     */
    public function after(?\DateTimeImmutable $next, int $added, int $removed, \DateTimeImmutable $now): self
    {
        $started = $this->started ?? $now;
        $added += $this->added;
        $removed += $this->removed;
        if (null === $next) {
            return new self(lastStarted: $started, lastFinished: $now, lastAdded: $added, lastRemoved: $removed);
        }

        return new self(
            $next,
            $started,
            $added,
            $removed,
            $this->lastStarted,
            $this->lastFinished,
            $this->lastAdded,
            $this->lastRemoved,
        );
    }

    /**
     * OK once a complete round found the index like Gerrit. A round that had to add or remove changes shows that
     * the index was not complete: the next round tells whether it is now.
     */
    public function result(): SystemCheckEnum
    {
        if (null === $this->lastFinished || $this->lastAdded + $this->lastRemoved > 0) {
            return SystemCheckEnum::ATTENTION;
        }

        return SystemCheckEnum::OK;
    }
}
