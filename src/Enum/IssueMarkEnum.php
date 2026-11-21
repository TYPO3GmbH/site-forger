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
 * A mark of a Forge issue that tells where help is needed, shown as a pill and searched by the filter "Marked as".
 * See App\Search\IssueMarkQuery for the issues of a mark.
 */
enum IssueMarkEnum
{
    /**
     * Forge has the field "Is Regression": something that worked before is broken now.
     */
    case REGRESSION;

    public function label(): string
    {
        return match ($this) {
            self::REGRESSION => 'Regression',
        };
    }

    /**
     * The key of the mark for styling: "status-pill-<severity>".
     */
    public function severity(): string
    {
        return match ($this) {
            self::REGRESSION => 'mark-regression',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::REGRESSION => 'actions-history',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::REGRESSION => 'It worked in an earlier version',
        };
    }

    public static function tryFromLabel(string $label): ?self
    {
        return array_find(self::cases(), static fn (self $mark): bool => $mark->label() === $label);
    }
}
