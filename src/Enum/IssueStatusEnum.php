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
 * Forge (Redmine) issue states, as stored in "status.name" of the issue index.
 */
enum IssueStatusEnum: string
{
    case NEW = 'New';
    case ACCEPTED = 'Accepted';
    case IN_PROGRESS = 'In Progress';
    case UNDER_REVIEW = 'Under Review';
    case NEEDS_FEEDBACK = 'Needs Feedback';
    case ON_HOLD = 'On Hold';
    case RESOLVED = 'Resolved';
    case CLOSED = 'Closed';
    case REJECTED = 'Rejected';

    /**
     * @return list<self>
     */
    public static function closed(): array
    {
        return [self::CLOSED, self::REJECTED, self::RESOLVED];
    }

    /**
     * The values of the closed states, ready to be used in an Elasticsearch "terms" query.
     *
     * @return list<string>
     */
    public static function closedValues(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::closed());
    }

    public function isClosed(): bool
    {
        return in_array($this, self::closed(), true);
    }
}
