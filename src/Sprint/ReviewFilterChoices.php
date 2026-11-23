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
use App\Repository\ChangeRepository;

/**
 * The values the open changes of the review index have for the filters of a board of reviews that offer a list,
 * with how many changes have each. The owners come from the search of people instead.
 */
class ReviewFilterChoices
{
    private const array PROPERTIES = ['tags', 'branches', 'releases', 'topics', 'mergeable'];

    private const int MAX_VALUES = 1000;

    /** @var array<string, array<array-key, int>>|null */
    private ?array $values = null;

    public function __construct(
        private readonly ChangeRepository $changes,
    ) {
    }

    /**
     * @return array<array-key, int> change count by value, the value with the most changes first
     */
    public function valuesOf(string $property): array
    {
        $this->values ??= $this->load();

        return $this->values[$property] ?? [];
    }

    /**
     * @return array<string, array<array-key, int>>
     */
    private function load(): array
    {
        $open = new ReviewBoardFilter();
        $open->workInProgress = true;
        $aggregations = [];
        foreach (self::PROPERTIES as $property) {
            $aggregations[$property] = [
                'terms' => ['field' => ReviewBoardFilter::FIELDS[$property], 'size' => self::MAX_VALUES],
            ];
        }
        $found = $this->changes->search(['query' => $open->toQuery(), 'size' => 0, 'aggregations' => $aggregations]);

        $values = [];
        foreach (self::PROPERTIES as $property) {
            $values[$property] = array_filter(
                $found->counts($property),
                // A change without a topic has "no topic" in the index
                static fn (int|string $key): bool => !in_array(trim((string) $key), ['', Change::NO_TOPIC], true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $values;
    }
}
