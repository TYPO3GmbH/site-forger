<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Twig;

use App\Enum\ActivityEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\PriorityEnum;
use App\Enum\ReviewStateEnum;
use App\Enum\ReviewVoteEnum;
use App\Enum\TrackerEnum;
use Twig\Attribute\AsTwigFunction;

/**
 * Gives templates the Forge and Gerrit enums, so labels and severities come from one place.
 */
final class StatusTwigExtension
{
    /**
     * The Forge state of a name ("status.name") or an id ("status.id"), null if Forge does not know it.
     */
    #[AsTwigFunction(name: 'issue_status')]
    public function issueStatus(int|string|null $nameOrId): ?IssueStatusEnum
    {
        return match (true) {
            null === $nameOrId => null,
            is_int($nameOrId) => IssueStatusEnum::tryFrom($nameOrId),
            default => IssueStatusEnum::tryFromLabel($nameOrId),
        };
    }

    /**
     * The state a Gerrit change document of the review index is shown in.
     *
     * @param array<string, mixed> $change
     */
    #[AsTwigFunction(name: 'review_state')]
    public function reviewState(array $change): ReviewStateEnum
    {
        return ReviewStateEnum::fromChange($change);
    }

    /**
     * The vote that counts for a label ("review" or "verification") of a Gerrit change document of the review index.
     *
     * @param array<string, mixed> $change
     */
    #[AsTwigFunction(name: 'review_vote')]
    public function reviewVote(array $change, string $label): ReviewVoteEnum
    {
        return ReviewVoteEnum::fromChange($change, $label);
    }

    /**
     * How long ago a Gerrit change document of the review index was last updated, null if it is closed.
     *
     * @param array<string, mixed> $change
     */
    #[AsTwigFunction(name: 'review_activity')]
    public function reviewActivity(array $change): ?ActivityEnum
    {
        return ActivityEnum::fromChange($change, new \DateTimeImmutable());
    }

    /**
     * How long ago a Forge issue document of the issue index was last updated, null if it is closed.
     *
     * @param array<string, mixed> $issue
     */
    #[AsTwigFunction(name: 'issue_activity')]
    public function issueActivity(array $issue): ?ActivityEnum
    {
        return ActivityEnum::fromIssue($issue, new \DateTimeImmutable());
    }

    /**
     * The Forge tracker of a name ("tracker.name") or an id ("tracker.id"), null if Forge does not know it.
     */
    #[AsTwigFunction(name: 'issue_tracker')]
    public function issueTracker(int|string|null $nameOrId): ?TrackerEnum
    {
        return match (true) {
            null === $nameOrId => null,
            is_int($nameOrId) => TrackerEnum::tryFrom($nameOrId),
            default => TrackerEnum::tryFromLabel($nameOrId),
        };
    }

    /**
     * The Forge priority of a name ("priority.name") or an id ("priority.id"), null if Forge does not know it.
     */
    #[AsTwigFunction(name: 'issue_priority')]
    public function issuePriority(int|string|null $nameOrId): ?PriorityEnum
    {
        return match (true) {
            null === $nameOrId => null,
            is_int($nameOrId) => PriorityEnum::tryFrom($nameOrId),
            default => PriorityEnum::tryFromLabel($nameOrId),
        };
    }
}
