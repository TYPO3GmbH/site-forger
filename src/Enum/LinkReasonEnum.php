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
 * Why an account may belong to another person, see App\People\LinkSuggestions. Backed: the page of the people
 * takes it as the parameter "reason".
 */
enum LinkReasonEnum: string
{
    case RENAMED = 'renamed';
    case SAME_NAME = 'same-name';

    public function label(): string
    {
        return match ($this) {
            self::RENAMED => 'Renamed',
            self::SAME_NAME => 'Same name',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RENAMED => 'A TYPO3 account renamed to "old_<username>" beside the account of the username',
            self::SAME_NAME => 'An account without a username of the name of one person with a username',
        };
    }
}
