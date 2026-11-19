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
 * Forge (Redmine) issue states.
 *
 * The value is the Redmine id, stored in "status.id" of the issue index, as listed by
 * forge.typo3.org/issue_statuses.json. The label is the name, stored in "status.name".
 */
enum IssueStatusEnum: int
{
    case NEW = 1;
    case ACCEPTED = 2;
    case IN_PROGRESS = 11;
    case UNDER_REVIEW = 8;
    case NEEDS_FEEDBACK = 4;
    case ON_HOLD = 7;
    case POSTPONED = 9;
    case RESOLVED = 3;
    case CLOSED = 5;
    case REJECTED = 6;

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'New',
            self::ACCEPTED => 'Accepted',
            self::IN_PROGRESS => 'In Progress',
            self::UNDER_REVIEW => 'Under Review',
            self::NEEDS_FEEDBACK => 'Needs Feedback',
            self::ON_HOLD => 'On Hold',
            self::POSTPONED => 'Postponed',
            self::RESOLVED => 'Resolved',
            self::CLOSED => 'Closed',
            self::REJECTED => 'Rejected',
        };
    }

    /**
     * The key of the state for styling, the same wherever the state is shown: "status-pill-<severity>".
     */
    public function severity(): string
    {
        return match ($this) {
            self::NEW => 'new',
            self::ACCEPTED => 'accepted',
            self::IN_PROGRESS => 'in-progress',
            self::UNDER_REVIEW => 'under-review',
            self::NEEDS_FEEDBACK => 'needs-feedback',
            self::ON_HOLD => 'on-hold',
            self::POSTPONED => 'postponed',
            self::RESOLVED => 'resolved',
            self::CLOSED => 'closed',
            self::REJECTED => 'rejected',
        };
    }

    /**
     * The state of a name as stored in "status.name", null for a name Forge does not know (any more).
     */
    public static function tryFromLabel(string $label): ?self
    {
        foreach (self::cases() as $state) {
            if ($state->label() === $label) {
                return $state;
            }
        }

        return null;
    }

    /**
     * @return list<self>
     */
    public static function closed(): array
    {
        return [self::CLOSED, self::REJECTED, self::RESOLVED];
    }

    /**
     * The names of the closed states, ready to be used in an Elasticsearch "terms" query on "status.name".
     *
     * @return list<string>
     */
    public static function closedLabels(): array
    {
        return array_map(static fn (self $state): string => $state->label(), self::closed());
    }

    public function isClosed(): bool
    {
        return in_array($this, self::closed(), true);
    }
}
