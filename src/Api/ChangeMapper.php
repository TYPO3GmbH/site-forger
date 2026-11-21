<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api;

use App\Api\Model\ChangeDetail;
use App\Api\Model\ChangeFile;
use App\Api\Model\ChangeIssue;
use App\Api\Model\ChangeSummary;
use App\Api\Model\GerritAccount;
use App\Api\Model\Label;
use App\Api\Model\ReviewMessage;
use App\Api\Model\Vote;
use App\Api\Model\Votes;
use App\Document\Change;
use App\Document\ChangedFile;
use App\Document\RelatedIssue;
use App\Document\ReviewMessage as StoredMessage;
use App\Document\SingleVote;
use App\Enum\ReviewLabelEnum;
use App\Import\GerritChangeImporter;
use App\People\Person;
use App\Twig\LinkTwigExtension;
use Doctrine\Common\Collections\ReadableCollection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns the changes of the index into the objects of the API. Email addresses never leave Forger: they are kept
 * for the avatars only.
 */
final readonly class ChangeMapper
{
    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function summary(Change $change): ChangeSummary
    {
        return new ChangeSummary(
            number: $change->id,
            changeId: $change->changeId,
            subject: $change->subject,
            state: $change->state()->value,
            branch: $change->branch,
            topic: $change->hasTopic() ? $change->topic : null,
            tags: $change->reviewTags,
            mergeable: $change->mergeable,
            insertions: $change->insertions,
            deletions: $change->deletions,
            patchSets: $change->patchsets,
            votes: new Votes(
                self::label($change, ReviewLabelEnum::CODE_REVIEW),
                self::label($change, ReviewLabelEnum::VERIFIED),
            ),
            commentCount: $change->commentCount,
            unresolvedCommentCount: $change->unresolvedCommentCount,
            owner: null === $change->owner ? null : new GerritAccount(
                $change->ownerId,
                $change->owner,
                self::publicUsername($change->ownerUsername),
            ),
            createdOn: ApiDate::fromIndex($change->createdOn),
            updatedOn: ApiDate::fromIndex($change->updatedOn),
            url: sprintf(LinkTwigExtension::GERRIT_CHANGE, $change->id),
            forgerUrl: $this->urls->generate(
                'change_show',
                ['number' => $change->id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        );
    }

    /**
     * @param ReadableCollection<int, RelatedIssue> $issues see Relations::issuesOf()
     */
    public function detail(Change $change, ReadableCollection $issues): ChangeDetail
    {
        return new ChangeDetail(
            summary: $this->summary($change),
            commitMessage: $change->commitMessage,
            releases: $change->releases,
            files: array_map(
                static fn (ChangedFile $file): ChangeFile => new ChangeFile(
                    $file->path,
                    $file->status,
                    $file->insertions,
                    $file->deletions,
                    $file->binary,
                    $file->oldPath,
                ),
                $change->fileDetails,
            ),
            issues: array_values($issues->map(static fn (RelatedIssue $related): ChangeIssue => new ChangeIssue(
                id: $related->id,
                relation: strtolower($related->relation),
                subject: $related->issue?->subject,
                status: $related->issue?->status['name'] ?? null,
                url: sprintf(LinkTwigExtension::FORGE_ISSUE, $related->id),
            ))->toArray()),
            messages: array_map(
                static fn (StoredMessage $message): ReviewMessage => new ReviewMessage(
                    self::account($message->author),
                    ApiDate::fromIndex($message->date),
                    $message->patchSet,
                    self::isBot($message->author),
                    $message->text,
                ),
                $change->messages,
            ),
            conflicts: $change->conflicts,
            cherryPickOf: $change->cherryPickOf,
        );
    }

    /**
     * A label with its vote, what it lacks for a merge, and each vote.
     */
    private static function label(Change $change, ReviewLabelEnum $label): Label
    {
        $votes = array_values(array_filter(
            $change->singleVotes,
            static fn (SingleVote $vote): bool => $label === $vote->label,
        ));

        return new Label(
            $change->vote($label)->value,
            $change->labelState($label),
            array_map(
                static fn (SingleVote $vote): Vote => new Vote(
                    self::account($vote->account),
                    $vote->value,
                    ApiDate::fromIndex($vote->date),
                    self::isBot($vote->account),
                ),
                $votes,
            ),
        );
    }

    /**
     * A person of Gerrit without the email address the index keeps to match people.
     */
    private static function account(?Person $person): ?GerritAccount
    {
        return null === $person ? null : new GerritAccount(
            $person->gerritId,
            $person->name,
            self::publicUsername($person->username),
        );
    }

    private static function isBot(?Person $person): bool
    {
        return in_array($person?->gerritId, GerritChangeImporter::CI_ACCOUNTS, true);
    }

    /**
     * The username, null when it is an email address: some people logged in to Gerrit with theirs.
     */
    public static function publicUsername(?string $username): ?string
    {
        return str_contains((string) $username, '@') ? null : $username;
    }
}
