<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

/**
 * The orders a list of Gerrit changes can be sorted in.
 *
 * No source system has ids for them: the value is the key in the URL ("?sort=smallest"), the label the name in the
 * list's sort field. Changes updated at the same time come smaller first, then those with more patch sets.
 */
enum ReviewSortEnum: string
{
    // In the order of the sort field: the quick reviews right after the default
    case RELEVANCE = 'relevance';
    case UPDATED = 'updated';
    case SMALLEST = 'smallest';
    case LEAST_UPDATED = 'least-updated';
    case NEWEST = 'newest';
    case OLDEST = 'oldest';
    case MOST_PATCHSETS = 'patchsets';

    private const array TIE_BREAKERS = [['insertions' => 'asc'], ['deletions' => 'asc'], ['patchsets' => 'desc']];

    public function label(): string
    {
        return match ($this) {
            self::RELEVANCE => 'Relevance',
            self::UPDATED => 'Recently updated',
            self::LEAST_UPDATED => 'Least recently updated',
            self::NEWEST => 'Newest',
            self::OLDEST => 'Oldest',
            self::SMALLEST => 'Smallest first',
            self::MOST_PATCHSETS => 'Most patch sets',
        };
    }

    /**
     * The "sort" of the search request.
     *
     * @return list<array<string, mixed>>
     */
    public function sort(): array
    {
        return match ($this) {
            self::RELEVANCE => [['_score' => 'desc'], ['updated_on' => 'desc']],
            self::UPDATED => [['updated_on' => 'desc'], ...self::TIE_BREAKERS],
            self::LEAST_UPDATED => [['updated_on' => 'asc'], ...self::TIE_BREAKERS],
            self::NEWEST => [['created_on' => 'desc']],
            self::OLDEST => [['created_on' => 'asc']],
            // The size is the lines added and removed together, as Gerrit shows it
            self::SMALLEST => [
                ['_script' => [
                    'type' => 'number',
                    'order' => 'asc',
                    // Without lines, as imported before them, a change counts as empty instead of failing the search
                    'script' => ['lang' => 'painless', 'source' => ReviewSizeEnum::LINES_SCRIPT],
                ]],
                ['updated_on' => 'desc'],
            ],
            self::MOST_PATCHSETS => [['patchsets' => 'desc'], ['updated_on' => 'desc']],
        };
    }

    /**
     * The orders a list offers. Relevance only means something when search words rank the changes.
     *
     * @return list<self>
     */
    public static function options(bool $withRelevance): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $sort): bool => $withRelevance || self::RELEVANCE !== $sort,
        ));
    }

    /**
     * The order of a URL value. An unknown value, or relevance where it is not offered, gives the default.
     *
     * @param self $listDefault the order of the list without search words
     */
    public static function fromRequest(string $value, bool $withRelevance, self $listDefault = self::UPDATED): self
    {
        $sort = self::tryFrom($value);
        if (null === $sort || !in_array($sort, self::options($withRelevance), true)) {
            return self::default($withRelevance, $listDefault);
        }

        return $sort;
    }

    /**
     * Relevance for search words, else the order of the list: most lists show the recently updated changes first.
     */
    public static function default(bool $withRelevance, self $listDefault = self::UPDATED): self
    {
        return $withRelevance ? self::RELEVANCE : $listDefault;
    }
}
