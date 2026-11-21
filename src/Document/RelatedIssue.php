<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

/**
 * An issue the footer of a change names, and how: see Relations::issuesOf().
 */
final readonly class RelatedIssue
{
    /**
     * @param string     $relation CommitMessage::RESOLVES or CommitMessage::RELATED
     * @param Issue|null $issue    null if Forger does not have the issue: it is in another project, or private
     */
    public function __construct(
        public int $id,
        public string $relation,
        public ?Issue $issue,
    ) {
    }
}
