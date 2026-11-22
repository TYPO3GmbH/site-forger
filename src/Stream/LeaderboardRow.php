<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Enum\StreamFigureEnum;
use App\People\Person;

/**
 * A place on the leaderboard: the person, all events, the events of each key figure, the lines added and the lines
 * removed by the changes of the person merged in the window, and the place in the window before, null when the
 * person had nothing to count there. See Leaderboards.
 */
final readonly class LeaderboardRow
{
    /**
     * @param array<string, int> $figures the events by the value of StreamFigureEnum
     */
    public function __construct(
        public int $rank,
        public Person $person,
        public int $events,
        public array $figures,
        public ?int $previousRank,
        public int $insertions = 0,
        public int $deletions = 0,
    ) {
    }

    /**
     * The lines changed: those added and those removed, which do not make up for each other.
     */
    public function linesChanged(): int
    {
        return $this->insertions + $this->deletions;
    }

    public function figure(StreamFigureEnum $figure): int
    {
        return $this->figures[$figure->value] ?? 0;
    }

    /**
     * How many places the person rose since the window before: negative when the person fell, null when new.
     */
    public function moved(): ?int
    {
        return null === $this->previousRank ? null : $this->previousRank - $this->rank;
    }
}
