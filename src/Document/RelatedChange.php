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
 * A change that belongs to an issue, and how: see Relations::changesOf().
 */
final readonly class RelatedChange
{
    /**
     * @param string $relation CommitMessage::RESOLVES, CommitMessage::RELATED or Relations::LINKED
     */
    public function __construct(
        public Change $change,
        public string $relation,
    ) {
    }
}
