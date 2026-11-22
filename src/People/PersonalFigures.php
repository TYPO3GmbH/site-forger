<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Document\Change;
use App\Document\Issue;

/**
 * What waits in My Work of a user, for the dashboard: the numbers of the lists of the overview and the first rows
 * of them, see PersonalWork and App\Controller\MyWorkController::overview().
 */
final readonly class PersonalFigures
{
    /**
     * @param int                                           $waitingChanges the own changes that wait for the user
     * @param int                                           $assignedIssues the open issues assigned to the user
     * @param int                                           $interests      the interests of the user
     * @param int                                           $reviewable     the changes of the interests the user did not vote on
     * @param int                                           $newIssues      the new issues of the interests
     * @param int                                           $followed       the people the user follows
     * @param int                                           $stuck          their changes that wait for their owner
     * @param int                                           $unreviewed     their changes without a review
     * @param int                                           $unanswered     their issues that are still new
     * @param list<array{kind: string, item: Change|Issue}> $waitingRows    the first of the own work that waits
     * @param list<array{kind: string, item: Change|Issue}> $interestRows   the first of the work of the interests
     * @param list<array{kind: string, item: Change|Issue}> $helpRows       the first of the work of the people
     *                                                                      followed that needs help
     */
    public function __construct(
        public int $waitingChanges,
        public int $assignedIssues,
        public int $interests,
        public int $reviewable,
        public int $newIssues,
        public int $followed,
        public int $stuck,
        public int $unreviewed,
        public int $unanswered,
        public array $waitingRows = [],
        public array $interestRows = [],
        public array $helpRows = [],
    ) {
    }

    public function waiting(): int
    {
        return $this->waitingChanges + $this->assignedIssues;
    }

    public function needingHelp(): int
    {
        return $this->stuck + $this->unreviewed + $this->unanswered;
    }
}
