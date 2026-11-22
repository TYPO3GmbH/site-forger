<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

/**
 * The firsts of new people in the window: how many contributions, how many in the window before, how many of each
 * milestone, and the newest of them.
 */
final readonly class StreamNewcomers
{
    /**
     * @param int                  $count      the first issues and the first changes, see
     *                                         StreamMilestoneEnum::isContribution()
     * @param array<string, int>   $milestones by the value of StreamMilestoneEnum
     * @param list<StreamNewcomer> $people     the newest first, at most StreamStatistics::NEWCOMERS
     */
    public function __construct(
        public int $count,
        public int $previous,
        public array $milestones,
        public array $people,
    ) {
    }
}
