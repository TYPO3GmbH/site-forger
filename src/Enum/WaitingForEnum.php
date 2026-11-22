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
 * Who an open Gerrit change waits for: the filter "Waiting for" of the search of the changes. The filter sends
 * the label, see App\Search\WaitingQuery for the changes of each.
 */
enum WaitingForEnum
{
    /**
     * A negative vote, a merge conflict or unresolved comments: the owner has to act.
     */
    case OWNER;

    /**
     * Nobody reviewed the current patch set yet, and nothing waits for the owner.
     */
    case FIRST_REVIEW;

    /**
     * The user logged in did not vote on the current patch set and does not own the change.
     */
    case YOUR_VOTE;

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Owner',
            self::FIRST_REVIEW => 'First review',
            self::YOUR_VOTE => 'Your vote',
        };
    }

    public static function tryFromLabel(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === $label) {
                return $case;
            }
        }

        return null;
    }
}
