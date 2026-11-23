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
 * What a sprint board shows: issues of Forge in the columns of their state, or open changes of Gerrit in the
 * columns of the votes they still need. The value goes to the database and to the address of a new board.
 */
enum BoardTypeEnum: string
{
    case ISSUES = 'issues';
    case REVIEWS = 'reviews';

    public function label(): string
    {
        return match ($this) {
            self::ISSUES => 'Issues',
            self::REVIEWS => 'Reviews',
        };
    }

    /**
     * What a board of the type shows, in a sentence for the form of a new board.
     */
    public function description(): string
    {
        return match ($this) {
            self::ISSUES => 'Issues of Forge by their state: blocked, open, in progress, in review, done.',
            self::REVIEWS => 'Open changes of Gerrit by the votes they still need.',
        };
    }
}
