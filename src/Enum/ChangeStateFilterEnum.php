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
 * Which changes the API lists by their status: the parameter "status" of /api/v1/changes.
 *
 * "open" holds the changes ready for review and those in progress, see ReviewStateEnum.
 */
enum ChangeStateFilterEnum: string
{
    case OPEN = 'open';
    case MERGED = 'merged';
    case ABANDONED = 'abandoned';
    case ALL = 'all';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::MERGED => 'Merged',
            self::ABANDONED => 'Abandoned',
            self::ALL => 'All',
        };
    }

    /**
     * The status of Gerrit the filter selects, null for all of them.
     */
    public function status(): ?ReviewStatusEnum
    {
        return match ($this) {
            self::OPEN => ReviewStatusEnum::NEW,
            self::MERGED => ReviewStatusEnum::MERGED,
            self::ABANDONED => ReviewStatusEnum::ABANDONED,
            self::ALL => null,
        };
    }
}
