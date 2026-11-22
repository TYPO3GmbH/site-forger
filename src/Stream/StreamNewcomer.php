<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use App\Document\Change;
use App\Document\Issue;
use App\Document\IssueJournal;
use App\Document\ReviewMessage;
use App\Enum\ReviewEventEnum;
use App\Enum\StreamMilestoneEnum;
use App\Enum\StreamNewcomerStateEnum;
use App\People\Person;

/**
 * A first of somebody: their first issue, their first change, or their first change merged, and where it stands now.
 * The person is the reporter of the issue or the owner of the change.
 */
final readonly class StreamNewcomer
{
    /**
     * @param string|null $date     in the format of the index: when it was reported, pushed or merged
     * @param Person|null $mergedBy who merged the change of a first merge, when the index has the message of it
     */
    public function __construct(
        public StreamMilestoneEnum $milestone,
        public Person $person,
        public int $number,
        public string $title,
        public ?string $date,
        public ?string $branch,
        public StreamNewcomerStateEnum $state,
        public ?Person $mergedBy = null,
    ) {
    }

    /**
     * The first issue of its reporter, null without one.
     */
    public static function ofIssue(?Issue $issue): ?StreamNewcomer
    {
        $reporter = $issue?->author;
        if (null === $issue || null === $reporter) {
            return null;
        }
        $answered = [] !== array_filter(
            $issue->history(),
            static fn (IssueJournal $journal): bool => !$journal->isByBot()
                && null !== $journal->author
                && $reporter->name !== $journal->author->name,
        );

        return new self(
            StreamMilestoneEnum::FIRST_ISSUE,
            $reporter,
            $issue->id,
            $issue->subject,
            $issue->createdOn,
            null,
            StreamNewcomerStateEnum::ofIssue($issue, $answered),
        );
    }

    /**
     * The owner without the email, see AGENTS.md. A first merge takes its date from Gerrit's submit, and who merged it
     * from the message of the merge. An older import lacks the submit: the message, or else the last update, gives
     * the date.
     */
    public static function ofChange(StreamMilestoneEnum $milestone, ?Change $change): ?StreamNewcomer
    {
        if (null === $change || null === $change->owner || '' === $change->owner) {
            return null;
        }
        $owner = new Person($change->owner, gerritId: $change->ownerId, username: $change->ownerUsername);
        if (StreamMilestoneEnum::FIRST_MERGE === $milestone) {
            $merge = self::merge($change);

            return new self(
                $milestone,
                $owner,
                $change->id,
                $change->subject,
                $change->submittedOn ?? $merge->date ?? $change->updatedOn,
                $change->branch,
                StreamNewcomerStateEnum::MERGED,
                $merge?->author,
            );
        }
        $reviewed = [] !== array_filter(
            $change->messages,
            static fn (ReviewMessage $message): bool => StreamEvents::isReview($change, $message),
        );

        return new self(
            $milestone,
            $owner,
            $change->id,
            $change->subject,
            $change->createdOn,
            $change->branch,
            StreamNewcomerStateEnum::ofChange($change, $reviewed),
        );
    }

    private static function merge(Change $change): ?ReviewMessage
    {
        foreach (array_reverse($change->messages) as $message) {
            if (ReviewEventEnum::MERGED === $message->event) {
                return $message;
            }
        }

        return null;
    }
}
