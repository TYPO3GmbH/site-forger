<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report;

use App\Document\Issue;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * An issue of the list of StaleIssues, with its changes in the order of their numbers.
 */
final readonly class StaleIssue
{
    /**
     * @param ReadableCollection<int, LinkedChange> $changes
     */
    public function __construct(
        public Issue $issue,
        public ReadableCollection $changes,
    ) {
    }
}
