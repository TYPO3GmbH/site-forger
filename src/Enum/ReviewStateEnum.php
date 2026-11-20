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
 * The state a Gerrit change is shown in.
 *
 * Gerrit keeps "work in progress" apart from the status (ReviewStatusEnum): it is a flag, stored in
 * "work_in_progress", and abandoned changes can have it, too. An open change is therefore either ready
 * for review or work in progress, a closed one is merged or abandoned, whatever the flag says.
 * The value is used in URLs.
 */
enum ReviewStateEnum: string
{
    case READY = 'ready';
    case WORK_IN_PROGRESS = 'wip';
    case MERGED = 'merged';
    case ABANDONED = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::READY => 'Ready for review',
            self::WORK_IN_PROGRESS => 'Work in progress',
            self::MERGED => 'Merged',
            self::ABANDONED => 'Abandoned',
        };
    }

    /**
     * The key of the state for styling, the same wherever the state is shown: "status-pill-<severity>".
     */
    public function severity(): string
    {
        return $this->value;
    }

    public function status(): ReviewStatusEnum
    {
        return match ($this) {
            self::READY, self::WORK_IN_PROGRESS => ReviewStatusEnum::NEW,
            self::MERGED => ReviewStatusEnum::MERGED,
            self::ABANDONED => ReviewStatusEnum::ABANDONED,
        };
    }

    /**
     * Whether a row of a list names the state: not when it is ready for review, as most open changes are and the
     * lists of open changes all are. The detail page and the cards name every state.
     */
    public function isNoteworthy(): bool
    {
        return self::READY !== $this;
    }

    public function isClosed(): bool
    {
        return $this->status()->isClosed();
    }

    /**
     * The Elasticsearch query matching the changes in this state.
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        $status = ['term' => ['reviewstatus' => $this->status()->value]];
        $workInProgress = ['term' => ['work_in_progress' => true]];

        return match ($this) {
            self::READY => ['bool' => ['filter' => [$status], 'must_not' => [$workInProgress]]],
            self::WORK_IN_PROGRESS => ['bool' => ['filter' => [$status, $workInProgress]]],
            self::MERGED, self::ABANDONED => $status,
        };
    }
}
