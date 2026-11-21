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

    /**
     * Easy to start with: Forge rates it easy or less, nobody is assigned and nobody started on it.
     */
    case GOOD_FIRST_ISSUE;

    /**
     * The values of the field "Complexity" of Forge that a good first issue has.
     */
    public const array EASY_COMPLEXITIES = ['no-brainer', 'trivial', 'easy'];

    /**
     * The states of a good first issue: "In Progress" or "Under Review" mean that somebody works on it.
     */
    public const array UNSTARTED_STATES = [IssueStatusEnum::NEW, IssueStatusEnum::ACCEPTED];

    public function label(): string
    {
        return match ($this) {
            self::REGRESSION => 'Regression',
            self::GOOD_FIRST_ISSUE => 'Good first issue',
        };
    }

    /**
     * The key of the mark for styling: "status-pill-<severity>".
     */
    public function severity(): string
    {
        return match ($this) {
            self::REGRESSION => 'mark-regression',
            self::GOOD_FIRST_ISSUE => 'mark-good-first-issue',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::REGRESSION => 'actions-history',
            self::GOOD_FIRST_ISSUE => 'actions-lightbulb',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::REGRESSION => 'It worked in an earlier version',
            self::GOOD_FIRST_ISSUE => 'Easy to start with, and nobody works on it yet',
        };
    }

    public static function tryFromLabel(string $label): ?self
    {
        return array_find(self::cases(), static fn (self $mark): bool => $mark->label() === $label);
    }
}
