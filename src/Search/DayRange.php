<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

/**
 * A range of whole days on a date of the issue or the review index, for the filters of the API.
 */
final class DayRange
{
    /**
     * The format of the dates of the indices, see the mappings of "created_on" and "updated_on".
     */
    private const string INDEX_DATE = 'Y/m/d H:i:s';

    /**
     * @param \DateTimeImmutable|null $since  the first day, included
     * @param \DateTimeImmutable|null $before the day after the last one, left out
     *
     * @return array<string, mixed>|null null without a day
     */
    public static function query(string $field, ?\DateTimeImmutable $since, ?\DateTimeImmutable $before): ?array
    {
        $range = array_filter([
            'gte' => $since?->format(self::INDEX_DATE),
            'lt' => $before?->format(self::INDEX_DATE),
        ]);

        return [] === $range ? null : ['range' => [$field => $range]];
    }
}
