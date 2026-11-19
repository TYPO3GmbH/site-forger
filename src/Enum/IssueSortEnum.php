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
 * The orders an issue list can be sorted in.
 *
 * No source system has ids for them: the value is the key in the URL ("?sort=newest"), the label the name in the
 * list's sort field.
 */
enum IssueSortEnum: string
{
    case RELEVANCE = 'relevance';
    case NEWEST = 'newest';
    case OLDEST = 'oldest';
    case UPDATED = 'updated';
    case LEAST_UPDATED = 'least-updated';
    case PRIORITY = 'priority';

    public function label(): string
    {
        return match ($this) {
            self::RELEVANCE => 'Relevance',
            self::NEWEST => 'Newest',
            self::OLDEST => 'Oldest',
            self::UPDATED => 'Recently updated',
            self::LEAST_UPDATED => 'Least recently updated',
            self::PRIORITY => 'Priority',
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
            self::NEWEST => [['created_on' => 'desc']],
            self::OLDEST => [['created_on' => 'asc']],
            self::UPDATED => [['updated_on' => 'desc']],
            self::LEAST_UPDATED => [['updated_on' => 'asc']],
            // The Redmine ids of the priorities go from "Must have" to "-- undefined --", see PriorityEnum.
            // An issue without a priority comes last, and an index without one sorts without an error.
            self::PRIORITY => [
                ['priority.id' => ['order' => 'asc', 'missing' => '_last', 'unmapped_type' => 'long']],
                ['updated_on' => 'desc'],
            ],
        };
    }

    /**
     * The orders a list offers. Relevance only means something when search words rank the issues.
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
     * The order of a URL value. An unknown value, or relevance where it is not offered, gives the default:
     * relevance for search words, the most recently updated issues first otherwise.
     */
    public static function fromRequest(string $value, bool $withRelevance): self
    {
        $sort = self::tryFrom($value);
        if (null === $sort || !in_array($sort, self::options($withRelevance), true)) {
            return self::default($withRelevance);
        }

        return $sort;
    }

    public static function default(bool $withRelevance): self
    {
        return $withRelevance ? self::RELEVANCE : self::UPDATED;
    }
}
