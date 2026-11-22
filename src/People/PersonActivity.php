<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

/**
 * What a person did lately, for the card of the person, as the figures of the statistics count it, see
 * StreamFigureEnum: the reviews, the new changes and patch sets, the issues created, the comments on issues, and all
 * events per week. See
 * PeopleActivity.
 */
final readonly class PersonActivity
{
    /**
     * The height of the sparkline in the units of its viewBox, see sparkline().
     */
    private const int HEIGHT = 30;

    /**
     * @param list<int> $weeks the events per week, the oldest first
     */
    public function __construct(
        public int $reviews,
        public int $patches,
        public int $issues,
        public int $comments,
        public array $weeks,
    ) {
    }

    public static function none(int $weeks): self
    {
        return new self(0, 0, 0, 0, array_fill(0, $weeks, 0));
    }

    public function events(): int
    {
        return array_sum($this->weeks);
    }

    /**
     * The points of the line of the weeks in a viewBox "0 0 100 30", as high as the busiest week, and of the area
     * below it. Empty with less than two weeks.
     *
     * @return array{line: string, area: string}
     */
    public function sparkline(): array
    {
        $count = count($this->weeks);
        if ($count < 2) {
            return ['line' => '', 'area' => ''];
        }
        $highest = max(1, ...$this->weeks);
        $step = 100 / ($count - 1);
        $points = [];
        foreach ($this->weeks as $index => $events) {
            $y = self::HEIGHT - 2 - ($events / $highest) * (self::HEIGHT - 4);
            $points[] = sprintf('%.2F,%.2F', $index * $step, $y);
        }
        $line = implode(' ', $points);

        return ['line' => $line, 'area' => sprintf('0,%d %s 100,%d', self::HEIGHT, $line, self::HEIGHT)];
    }
}
