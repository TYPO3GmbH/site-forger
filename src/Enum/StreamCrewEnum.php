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
 * The people who carry the review in Gerrit, see StreamCrew: the work should spread over many of them. Backed: the API
 * returns the value as the key.
 */
enum StreamCrewEnum: string
{
    case MERGING = 'merging';
    case VOTING = 'voting';

    public function label(): string
    {
        return match ($this) {
            self::MERGING => 'Merges',
            self::VOTING => 'Votes',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MERGING => 'Who merged changes in Gerrit',
            self::VOTING => 'Who voted on changes in Gerrit, without the tools',
        };
    }
}
