<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Duplicates;

use App\Document\Issue;

/**
 * One issue on the duplicates page of another.
 *
 * The similarity is the mean of both directions, see DuplicateSearch, so both issues get the same value. It is
 * null when the search did not find the issue. An issue Forge relates as a duplicate has a relation.
 */
final readonly class DuplicateHit
{
    /**
     * @param int|null $forward  how similar the issue is, seen from the issue of the page
     * @param int|null $backward how similar the issue of the page is, seen from this issue
     */
    public function __construct(
        public Issue $issue,
        public ?int $similarity,
        public ?int $forward = null,
        public ?int $backward = null,
        public ?string $relation = null,
    ) {
    }

    public static function checked(Issue $issue, int $forward, int $backward): self
    {
        return new self($issue, (int) round(($forward + $backward) / 2), $forward, $backward);
    }

    public function withRelation(string $relation): self
    {
        return new self($this->issue, $this->similarity, $this->forward, $this->backward, $relation);
    }
}
