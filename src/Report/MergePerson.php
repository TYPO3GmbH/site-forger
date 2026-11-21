<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report;

use App\Document\Change;
use App\People\Person;

/**
 * A person behind a merge, see GetInvolved::recentPeople(): who wrote or reviewed the change.
 */
final readonly class MergePerson
{
    public function __construct(
        public Person $person,
        public Change $change,
        public bool $author,
    ) {
    }

    /**
     * What the person did, for the title of the face: "wrote" or "reviewed", with the title of the change.
     */
    public function deed(): string
    {
        return sprintf('%s %s: %s', $this->person->name, $this->author ? 'wrote' : 'reviewed', $this->change->title());
    }
}
