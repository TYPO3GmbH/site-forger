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
 * A vote of a Gerrit label ("Code-Review" or "Verified"), the value is the Gerrit value.
 *
 * Only mergers may vote +2 and -2, so these weigh more than the votes of everybody else.
 */
enum ReviewVoteEnum: int
{
    case REJECTED = -2;
    case DISLIKED = -1;
    case NONE = 0;
    case RECOMMENDED = 1;
    case APPROVED = 2;

    /**
     * The labels of the review index: the name used in its fields => the name in Gerrit.
     */
    public const array LABELS = [
        'review' => 'Code-Review',
        'verification' => 'Verified',
    ];

    public function label(): string
    {
        return $this->value > 0 ? '+' . $this->value : (string) $this->value;
    }

    /**
     * The key of the vote for styling: "status-pill-<severity>".
     */
    public function severity(): string
    {
        return 'vote-' . strtolower($this->name);
    }

    public function isStrong(): bool
    {
        return self::APPROVED === $this || self::REJECTED === $this;
    }
}
