<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Document\Change;
use App\Document\Issue;
use App\Elastic\SearchResult;

/**
 * Picks the rows of the cards of "For you" on the dashboard, card by card. A card leaves out what a card before
 * it shows, so every change and issue shows once.
 */
final class PersonalRows
{
    /**
     * @var array<string, true> the changes and issues the cards show so far
     */
    private array $shown = [];

    /**
     * @param int $size the rows of a card
     */
    public function __construct(private readonly int $size)
    {
    }

    /**
     * How many documents a list of the next card needs: its rows, and as many more as the cards before it show.
     */
    public function candidates(): int
    {
        return $this->size + count($this->shown);
    }

    /**
     * The rows of the next card, taken in turn from its lists: each list with documents shows at least one.
     *
     * @param SearchResult<Change|Issue> ...$lists
     *
     * @return list<array{kind: string, item: Change|Issue}> the kind is "change" or "issue"
     */
    public function take(SearchResult ...$lists): array
    {
        $queues = array_map(
            fn (SearchResult $list): array => array_values(array_filter($list->toArray(), $this->isNew(...))),
            $lists,
        );
        $rows = [];
        $rounds = [] === $queues ? 0 : max(array_map(count(...), $queues));
        for ($round = 0; $round < $rounds; ++$round) {
            foreach ($queues as $queue) {
                $item = $queue[$round] ?? null;
                if (null === $item || count($rows) >= $this->size || !$this->isNew($item)) {
                    continue;
                }
                $this->shown[self::key($item)] = true;
                $rows[] = ['kind' => self::kind($item), 'item' => $item];
            }
        }

        return $rows;
    }

    private function isNew(Change|Issue $item): bool
    {
        return !isset($this->shown[self::key($item)]);
    }

    private static function key(Change|Issue $item): string
    {
        return self::kind($item) . ':' . $item->id;
    }

    private static function kind(Change|Issue $item): string
    {
        return $item instanceof Change ? 'change' : 'issue';
    }
}
