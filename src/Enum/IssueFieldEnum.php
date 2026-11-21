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
 * A field of a Forge issue a journal changes, see App\Document\IssueJournal. The label names it in the history.
 */
enum IssueFieldEnum
{
    case STATUS;
    case TRACKER;
    case PRIORITY;
    case ASSIGNEE;
    case CATEGORY;
    case TARGET_VERSION;
    case SUBJECT;
    case DESCRIPTION;
    case DONE;
    case PARENT;
    case SUBTASK;
    case RELATION;
    case CUSTOM_FIELD;
    case ATTACHMENT;
    case OTHER;

    public function label(): string
    {
        return match ($this) {
            self::STATUS => 'status',
            self::TRACKER => 'tracker',
            self::PRIORITY => 'priority',
            self::ASSIGNEE => 'assignee',
            self::CATEGORY => 'category',
            self::TARGET_VERSION => 'target version',
            self::SUBJECT => 'subject',
            self::DESCRIPTION => 'description',
            self::DONE => 'done',
            self::PARENT => 'parent task',
            self::SUBTASK => 'subtask',
            self::RELATION => 'relation',
            self::CUSTOM_FIELD => 'field',
            self::ATTACHMENT => 'file',
            self::OTHER => 'field',
        };
    }

    /**
     * Whether the history shows the values: an id of Forge that Forger cannot name, or a long text, it does not.
     */
    public function showsValues(): bool
    {
        return !in_array($this, [self::CATEGORY, self::TARGET_VERSION, self::DESCRIPTION, self::OTHER], true);
    }
}
