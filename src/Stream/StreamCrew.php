<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Enum\StreamCrewEnum;

/**
 * The people of a crew in the window and how widely they spread the work. The carriers are the fewest people who
 * together did half of it. The more carriers, the better: few carriers are a risk.
 */
final readonly class StreamCrew
{
    /**
     * @param list<int> $counts         the merges or votes of each person, the busiest first
     * @param list<int> $previousCounts the same for the window before
     */
    public function __construct(
        public StreamCrewEnum $crew,
        private array $counts,
        private array $previousCounts,
    ) {
    }

    public function people(): int
    {
        return count($this->counts);
    }

    public function carriers(): int
    {
        return self::carriersOf($this->counts);
    }

    public function previousCarriers(): int
    {
        return self::carriersOf($this->previousCounts);
    }

    /**
     * The percent of the work each carrier did, the busiest first.
     *
     * @return list<float>
     */
    public function carrierShares(): array
    {
        $total = array_sum($this->counts);

        return array_map(
            static fn (int $count): float => round($count / $total * 100, 1),
            array_slice($this->counts, 0, $this->carriers()),
        );
    }

    /**
     * @param list<int> $counts the busiest first
     */
    private static function carriersOf(array $counts): int
    {
        $total = array_sum($counts);
        $done = 0;
        foreach ($counts as $index => $count) {
            $done += $count;
            if (2 * $done >= $total) {
                return $index + 1;
            }
        }

        return 0;
    }
}
