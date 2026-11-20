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
     * relevance for search words, the order of the list otherwise.
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
     * Relevance for search words, else the order of the list: most lists show the recently updated issues first.
     */
    public static function default(bool $withRelevance, self $listDefault = self::UPDATED): self
    {
        return $withRelevance ? self::RELEVANCE : $listDefault;
    }
}
