<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api;

use App\Api\Model\ChainChange;
use App\Api\Model\ChangeDetail;
use App\Api\Model\ChangeFile;
use App\Api\Model\ChangeIssue;
use App\Api\Model\ChangeSummary;
use App\Api\Model\CodeComment;
use App\Api\Model\ConflictingChange;
use App\Api\Model\GerritAccount;
use App\Api\Model\Label;
use App\Api\Model\ReviewMessage;
use App\Api\Model\Vote;
use App\Api\Model\Votes;
use App\Document\ChainLink;
use App\Document\Change;
use App\Document\ChangedFile;
use App\Document\CodeComment as StoredComment;
use App\Document\RelatedIssue;
use App\Document\ReviewMessage as StoredMessage;
use App\Document\SingleVote;
use App\Enum\ReviewLabelEnum;
use App\People\Person;
use App\Twig\LinkTwigExtension;
use Doctrine\Common\Collections\ArrayCollection;
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
            closedOn: $change->state()->isClosed() ? ApiDate::fromIndex($change->closedOn) : null,
            url: sprintf(LinkTwigExtension::GERRIT_CHANGE, $change->id),
            forgerUrl: $this->urls->generate(
                'change_show',
                ['number' => $change->id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        );
    }

    /**
     * @param ReadableCollection<int, RelatedIssue> $issues    see Relations::issuesOf()
     * @param ReadableCollection<int, Change>       $chain     the changes of the chain Forger has, by number, see
     *                                                         Relations::chainOf()
     * @param ReadableCollection<int, Change>       $conflicts the conflicting changes Forger has, by number, see
     *                                                         Relations::mergeConflictsOf()
     */
    public function detail(
        Change $change,
        ReadableCollection $issues,
        ReadableCollection $chain,
        ReadableCollection $conflicts = new ArrayCollection(),
    ): ChangeDetail {
        $place = static fn (ChainLink $link): string => match (true) {
            $link->number === $change->id => 'this',
            in_array($link, $change->chainAbove(), true) => 'above',
            default => 'below',
        };

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
                    $message->id,
                    self::account($message->author),
                    ApiDate::fromIndex($message->date),
                    $message->patchSet,
                    $message->isByCi(),
                    $message->text,
                ),
                $change->messages,
            ),
            conflicts: $change->conflicts,
            cherryPickOf: $change->cherryPickOf,
            chain: $change->hasChain() ? array_map(
                static fn (ChainLink $link): ChainChange => new ChainChange(
                    number: $link->number,
                    subject: $link->subject,
                    place: $place($link),
                    state: $chain->get($link->number)?->state()->value,
                    patchSet: $link->patchSet,
                    currentPatchSet: $link->currentPatchSet,
                    outdated: $link->isOutdated($chain->get($link->number)),
                    url: sprintf(LinkTwigExtension::GERRIT_CHANGE, $link->number),
                ),
                $change->chain,
            ) : [],
            comments: array_map(
                static fn (StoredComment $comment): CodeComment => new CodeComment(
                    id: $comment->id,
                    messageId: $comment->messageId,
                    inReplyTo: $comment->inReplyTo,
                    author: self::account($comment->author),
                    createdOn: ApiDate::fromIndex($comment->date),
                    patchSet: $comment->patchSet,
                    file: $comment->file,
                    line: $comment->line,
                    unresolved: $change->isOpen($comment),
                    text: $comment->text,
                ),
                $change->comments,
            ),
            mergeConflicts: array_map(
                static fn (int $number): ConflictingChange => new ConflictingChange(
                    $number,
                    $conflicts->get($number)?->subject,
                    $conflicts->get($number)?->state()->value,
                    sprintf(LinkTwigExtension::GERRIT_CHANGE, $number),
                ),
                $change->mergeConflicts,
            ),
        );
    }

    /**
     * A label with its vote, what it lacks for a merge, and each vote.
     */
    private static function label(Change $change, ReviewLabelEnum $label): Label
    {
        $votes = $change->votesOn($label);

        return new Label(
            $change->vote($label)->value,
            $change->labelState($label)?->value,
            array_map(
                static fn (SingleVote $vote): Vote => new Vote(
                    self::account($vote->account),
                    $vote->value,
                    ApiDate::fromIndex($vote->date),
                    $vote->isByCi(),
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

    /**
     * The username, null when it is an email address: some people logged in to Gerrit with theirs.
     */
    public static function publicUsername(?string $username): ?string
    {
        return str_contains((string) $username, '@') ? null : $username;
    }
}
