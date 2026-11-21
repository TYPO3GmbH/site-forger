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
 * Which issues the API lists by their status: the parameter "status" of /api/v1/issues.
 */
enum IssueStateFilterEnum: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case ALL = 'all';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::CLOSED => 'Closed',
            self::ALL => 'All',
        };
    }
}
