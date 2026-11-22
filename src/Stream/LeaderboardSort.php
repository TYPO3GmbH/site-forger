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
use App\Enum\StreamSourceEnum;

/**
 * What the leaderboard sorts by: all events, a key figure of the statistics, or the lines added, removed or
 * changed by the changes merged. The parameter "sort" of the page has its value: empty, the value of the figure,
 * "added", "removed" or "changed". Lines added and removed do not make up for each other: 100 added and 100
 * removed are 200 changed.
 */
final readonly class LeaderboardSort
{
    public const string ADDED = 'added';
    public const string REMOVED = 'removed';
    public const string CHANGED = 'changed';

    /**
     * @param self::ADDED|self::REMOVED|self::CHANGED|null $lines
     */
    private function __construct(
        public ?StreamFigureEnum $figure,
        public ?string $lines,
    ) {
    }

    /**
     * All events for a value it does not know.
     */
    public static function fromValue(string $value): self
    {
        return match ($value) {
            self::ADDED => new self(null, self::ADDED),
            self::REMOVED => new self(null, self::REMOVED),
            self::CHANGED => new self(null, self::CHANGED),
            default => new self(StreamFigureEnum::tryFrom($value), null),
        };
    }

    /**
     * The sort for the events of the source: all events for a figure of the other source, and for the lines with
     * Forge. The lines are those of the changes on Gerrit.
     */
    public function forSource(?StreamSourceEnum $source): self
    {
        $fits = match (true) {
            null === $source => true,
            null !== $this->lines => StreamSourceEnum::GERRIT === $source,
            default => null === $this->figure || $this->figure->source() === $source,
        };

        return $fits ? $this : new self(null, null);
    }

    /**
     * The value of the parameter "sort", null for all events.
     */
    public function value(): ?string
    {
        return $this->lines ?? $this->figure?->value;
    }

    /**
     * The number of a place it sorts by.
     */
    public function countOf(LeaderboardRow $row): int
    {
        return match (true) {
            self::ADDED === $this->lines => $row->insertions,
            self::REMOVED === $this->lines => $row->deletions,
            self::CHANGED === $this->lines => $row->linesChanged(),
            null !== $this->figure => $row->figure($this->figure),
            default => $row->events,
        };
    }
}
