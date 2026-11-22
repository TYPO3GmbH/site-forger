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
 * A first of a new contributor: the first issue on Forge, the first change in Gerrit, the first change of theirs that
 * was merged. Backed: the API returns the value. The first issue and the first change are contributions, the first
 * merge is what came of one.
 */
enum StreamMilestoneEnum: string
{
    case FIRST_ISSUE = 'first-issue';
    case FIRST_CHANGE = 'first-change';
    case FIRST_MERGE = 'first-merge';

    public function label(): string
    {
        return match ($this) {
            self::FIRST_ISSUE => 'First issue',
            self::FIRST_CHANGE => 'First change',
            self::FIRST_MERGE => 'First merge',
        };
    }

    public function source(): StreamSourceEnum
    {
        return self::FIRST_ISSUE === $this ? StreamSourceEnum::FORGE : StreamSourceEnum::GERRIT;
    }

    /**
     * The event that reaches the milestone: an issue or a change created, a change merged.
     */
    public function kind(): StreamKindEnum
    {
        return self::FIRST_MERGE === $this ? StreamKindEnum::MERGED : StreamKindEnum::CREATED;
    }

    /**
     * Whether it counts as a new contribution, as "New contributions" counts them.
     */
    public function isContribution(): bool
    {
        return self::FIRST_MERGE !== $this;
    }
}
