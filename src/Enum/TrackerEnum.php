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
 * Forge (Redmine) trackers.
 *
 * The value is the Redmine id, stored in "tracker.id" of the issue index, as listed by
 * forge.typo3.org/trackers.json. The label is the name, stored in "tracker.name".
 */
enum TrackerEnum: int
{
    case BUG = 1;
    case FEATURE = 2;
    case SUPPORT = 3;
    case TASK = 4;
    case MAJOR_FEATURE = 5;
    case STORY = 6;
    case SUGGESTION = 7;
    case IMPEDIMENT = 9;
    case EPIC = 10;
    case WORK_PACKAGE = 11;
    case TOPIC = 12;

    public function label(): string
    {
        return match ($this) {
            self::BUG => 'Bug',
            self::FEATURE => 'Feature',
            self::SUPPORT => 'Support',
            self::TASK => 'Task',
            self::MAJOR_FEATURE => 'Major Feature',
            self::STORY => 'Story',
            self::SUGGESTION => 'Suggestion',
            self::IMPEDIMENT => 'Impediment',
            self::EPIC => 'Epic',
            self::WORK_PACKAGE => 'Work Package',
            self::TOPIC => 'Topic',
        };
    }

    /**
     * The key of the tracker for styling: "issue-type-<severity>" of the template bundle,
     * which styles the trackers of the TYPO3 Core team and "default" for the others.
     */
    public function severity(): string
    {
        return match ($this) {
            self::BUG => 'bug',
            self::FEATURE, self::MAJOR_FEATURE => 'feature',
            self::TASK => 'task',
            self::STORY => 'story',
            self::EPIC => 'epic',
            default => 'default',
        };
    }

    /**
     * The trackers the issue manager can leave out, in the order of its checkboxes.
     *
     * @return list<self>
     */
    public static function filterable(): array
    {
        return [self::EPIC, self::STORY, self::FEATURE, self::TASK, self::BUG];
    }

    /**
     * The tracker of a name as stored in "tracker.name", null for a name Forge does not know (any more).
     */
    public static function tryFromLabel(string $label): ?self
    {
        foreach (self::cases() as $tracker) {
            if ($tracker->label() === $label) {
                return $tracker;
            }
        }

        return null;
    }
}
