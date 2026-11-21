<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Document;

use App\Enum\IssueFieldEnum;

/**
 * A field a journal of a Forge issue changes, with the values before and after as the history shows them, see
 * IssueJournal. For the assignee the values are numbers of Forge users, for a relation, the parent and a subtask
 * the numbers of issues.
 */
final readonly class IssueFieldChange
{
    /**
     * @param string $name the name of the field, for a custom field and a relation, like "TYPO3 Version"
     */
    public function __construct(
        public IssueFieldEnum $field,
        public string $name,
        public ?string $from,
        public ?string $to,
    ) {
    }

    public function isAdded(): bool
    {
        return null === $this->from && null !== $this->to;
    }

    public function isRemoved(): bool
    {
        return null !== $this->from && null === $this->to;
    }
}
