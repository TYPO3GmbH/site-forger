<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\ReviewStatusEnum;
use App\Enum\StreamQueueAgeEnum;

/**
 * The open changes that wait for a review, by the time since their last activity. Changes in progress are left
 * out: their owners do not ask for a review yet.
 */
final class ReviewQueueQuery
{
    public const string AGES = 'ages';

    /**
     * @return array<string, mixed>
     */
    public static function request(?string $branch): array
    {
        $open = [['term' => ['reviewstatus' => ReviewStatusEnum::NEW->value]]];
        if (null !== $branch) {
            $open[] = ['term' => ['branch' => $branch]];
        }
        $ranges = array_map(
            static fn (StreamQueueAgeEnum $age): array => $age->range(),
            StreamQueueAgeEnum::cases(),
        );

        return [
            'query' => ['bool' => [
                'filter' => $open,
                'must_not' => [['term' => ['work_in_progress' => true]]],
            ]],
            'size' => 0,
            'aggs' => [self::AGES => ['date_range' => ['field' => 'updated_on', 'ranges' => $ranges]]],
        ];
    }
}
