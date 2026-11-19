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
 * Forge (Redmine) issue priorities.
 *
 * The value is the Redmine id, stored in "priority.id" of the issue index, as listed by
 * forge.typo3.org/enumerations/issue_priorities.json. The label is the name, stored in "priority.name".
 */
enum PriorityEnum: int
{
    case MUST_HAVE = 3;
    case SHOULD_HAVE = 4;
    case COULD_HAVE = 5;
    case WONT_HAVE = 6;
    case UNDEFINED = 7;

    public function label(): string
    {
        return match ($this) {
            self::MUST_HAVE => 'Must have',
            self::SHOULD_HAVE => 'Should have',
            self::COULD_HAVE => 'Could have',
            self::WONT_HAVE => "Won't have this time",
            self::UNDEFINED => '-- undefined --',
        };
    }

    /**
     * The key of the priority for styling: "priority-<severity>".
     */
    public function severity(): string
    {
        return match ($this) {
            self::MUST_HAVE => 'must',
            self::SHOULD_HAVE => 'should',
            self::COULD_HAVE => 'could',
            self::WONT_HAVE => 'wont',
            self::UNDEFINED => 'undefined',
        };
    }

    /**
     * Forge gives new issues this priority, lists only point out the others.
     */
    public function isDefault(): bool
    {
        return self::SHOULD_HAVE === $this;
    }

    /**
     * The priority of a name as stored in "priority.name", null for a name Forge does not know (any more).
     */
    public static function tryFromLabel(string $label): ?self
    {
        foreach (self::cases() as $priority) {
            if ($priority->label() === $label) {
                return $priority;
            }
        }

        return null;
    }
}
