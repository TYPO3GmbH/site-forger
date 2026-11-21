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
 * What a label of a Gerrit change lacks for a merge, as Gerrit tells it in "submit_records". The value is the one of
 * Gerrit, stored in "label_states" of the review index.
 */
enum ReviewLabelStateEnum: string
{
    case OK = 'OK';
    case NEED = 'NEED';
    case REJECT = 'REJECT';
    case IMPOSSIBLE = 'IMPOSSIBLE';
    case MAY = 'MAY';

    public function label(): string
    {
        return match ($this) {
            self::OK => 'Done',
            self::NEED => 'Needs a vote',
            self::REJECT => 'Blocked',
            self::IMPOSSIBLE => 'Cannot be met',
            self::MAY => 'Optional',
        };
    }

    /**
     * The key of the state for styling: "status-pill-<severity>".
     */
    public function severity(): string
    {
        return 'label-' . strtolower($this->name);
    }
}
