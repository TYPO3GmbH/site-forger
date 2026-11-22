<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

/**
 * The time a run of an import may take. A delta first loads what changed. It then fills the time left with the
 * documents of an older import, so a run takes about its five minutes and the next one starts on time.
 */
final readonly class RunBudget
{
    private float $startedAt;

    /**
     * @param (\Closure(): float)|null $clock the seconds now, for tests
     */
    public function __construct(
        private int $seconds,
        private ?\Closure $clock = null,
    ) {
        $this->startedAt = $this->now();
    }

    public function hasTimeLeft(): bool
    {
        return $this->now() - $this->startedAt < $this->seconds;
    }

    /**
     * Hands out the items batch by batch while time is left. A batch that started runs to its end.
     *
     * @template T
     *
     * @param list<T>                 $items
     * @param positive-int            $size  the items of a batch
     * @param \Closure(list<T>): void $work
     *
     * @return int how many items it handed out
     */
    public function each(array $items, int $size, \Closure $work): int
    {
        $done = 0;
        foreach (array_chunk($items, $size) as $batch) {
            if (!$this->hasTimeLeft()) {
                break;
            }
            $work($batch);
            $done += count($batch);
        }

        return $done;
    }

    private function now(): float
    {
        return null === $this->clock ? microtime(true) : ($this->clock)();
    }
}
