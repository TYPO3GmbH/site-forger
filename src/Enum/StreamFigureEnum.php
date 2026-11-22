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
 * The key figures of the statistics of the activity: what the people got done, not how many events there were.
 * Backed: the API returns the value as the key of a figure. Each one counts the events of a channel and of kinds,
 * see StreamInsightsQuery::figures().
 */
enum StreamFigureEnum: string
{
    case ISSUES_CREATED = 'issues-created';
    case ISSUES_RESOLVED = 'issues-resolved';
    case ISSUES_CLOSED = 'issues-closed';
    case ISSUE_COMMENTS = 'issue-comments';
    case NEW_CHANGES = 'new-changes';
    case PATCH_SETS = 'patch-sets';
    case REVIEWS = 'reviews';
    case CHANGES_MERGED = 'changes-merged';
    case CHANGES_ABANDONED = 'changes-abandoned';

    public function label(): string
    {
        return match ($this) {
            self::ISSUES_CREATED => 'Issues created',
            self::ISSUES_RESOLVED => 'Issues resolved',
            self::ISSUES_CLOSED => 'Closed or rejected',
            self::ISSUE_COMMENTS => 'Issue comments',
            self::NEW_CHANGES => 'New changes',
            self::PATCH_SETS => 'Patch sets',
            self::REVIEWS => 'Reviews',
            self::CHANGES_MERGED => 'Merged',
            self::CHANGES_ABANDONED => 'Abandoned',
        };
    }

    /**
     * The label below the heading of its channel, where room is short, like the columns of the leaderboard.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::ISSUES_CREATED => 'Created',
            self::ISSUES_RESOLVED => 'Resolved',
            self::ISSUES_CLOSED => 'Closed',
            self::ISSUE_COMMENTS => 'Comments',
            self::NEW_CHANGES => 'New',
            self::PATCH_SETS => 'Patch sets',
            self::REVIEWS => 'Reviews',
            self::CHANGES_MERGED => 'Merged',
            self::CHANGES_ABANDONED => 'Abandoned',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ISSUES_CREATED => 'New issues on Forge',
            self::ISSUES_RESOLVED => 'Issues fixed: their status went to "Resolved"',
            self::ISSUES_CLOSED => 'Issues closed or rejected without a fix',
            self::ISSUE_COMMENTS => 'Comments on Forge issues',
            self::NEW_CHANGES => 'Changes pushed to Gerrit for the first time',
            self::PATCH_SETS => 'New patch sets of changes, the reworks',
            self::REVIEWS => 'Votes and comments in Gerrit',
            self::CHANGES_MERGED => 'Changes merged in Gerrit',
            self::CHANGES_ABANDONED => 'Changes abandoned in Gerrit',
        };
    }

    /**
     * More reports, reworks or issues closed without a fix are not good or bad by themselves.
     */
    public function better(): StreamBetterEnum
    {
        return match ($this) {
            self::ISSUES_CREATED,
            self::ISSUES_CLOSED,
            self::PATCH_SETS,
            self::CHANGES_ABANDONED => StreamBetterEnum::NEITHER,
            self::ISSUES_RESOLVED,
            self::ISSUE_COMMENTS,
            self::NEW_CHANGES,
            self::REVIEWS,
            self::CHANGES_MERGED => StreamBetterEnum::MORE,
        };
    }

    /**
     * 1 when its events open issues or changes, -1 when they close them, else 0: what comes in and what goes out.
     */
    public function openDelta(): int
    {
        return match ($this) {
            self::ISSUES_CREATED, self::NEW_CHANGES => 1,
            self::ISSUES_RESOLVED, self::ISSUES_CLOSED, self::CHANGES_MERGED, self::CHANGES_ABANDONED => -1,
            self::ISSUE_COMMENTS, self::PATCH_SETS, self::REVIEWS => 0,
        };
    }

    /**
     * The channel of its events. A review in Gerrit is a vote or a comment, so the comments of Forge count apart.
     */
    public function source(): StreamSourceEnum
    {
        return match ($this) {
            self::ISSUES_CREATED,
            self::ISSUES_RESOLVED,
            self::ISSUES_CLOSED,
            self::ISSUE_COMMENTS => StreamSourceEnum::FORGE,
            self::NEW_CHANGES,
            self::PATCH_SETS,
            self::REVIEWS,
            self::CHANGES_MERGED,
            self::CHANGES_ABANDONED => StreamSourceEnum::GERRIT,
        };
    }

    /**
     * @return list<StreamKindEnum>
     */
    public function kinds(): array
    {
        return match ($this) {
            self::ISSUES_CREATED, self::NEW_CHANGES => [StreamKindEnum::CREATED],
            self::ISSUES_RESOLVED => [StreamKindEnum::RESOLVED],
            self::ISSUES_CLOSED, self::CHANGES_ABANDONED => [StreamKindEnum::CLOSED],
            self::ISSUE_COMMENTS => [StreamKindEnum::COMMENTED],
            self::PATCH_SETS => [StreamKindEnum::UPLOADED],
            self::REVIEWS => [StreamKindEnum::VOTED, StreamKindEnum::COMMENTED],
            self::CHANGES_MERGED => [StreamKindEnum::MERGED],
        };
    }

    /**
     * The key of its color: "--stream-<key>" of assets/css/module/_stream.scss, the one of its channel.
     */
    public function colorKey(): string
    {
        return $this->source()->colorKey();
    }
}
