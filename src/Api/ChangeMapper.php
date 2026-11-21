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
use App\Api\Model\ChangeIssue;
use App\Api\Model\ChangeSummary;
use App\Api\Model\Owner;
use App\Api\Model\Votes;
use App\Document\Change;
use App\Document\RelatedIssue;
use App\Enum\ReviewLabelEnum;
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
                $change->vote(ReviewLabelEnum::CODE_REVIEW)->value,
                $change->vote(ReviewLabelEnum::VERIFIED)->value,
            ),
            owner: null === $change->owner ? null : new Owner(
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
            files: $change->files,
            issues: array_values($issues->map(static fn (RelatedIssue $related): ChangeIssue => new ChangeIssue(
                id: $related->id,
                relation: strtolower($related->relation),
                subject: $related->issue?->subject,
                status: $related->issue?->status['name'] ?? null,
                url: sprintf(LinkTwigExtension::FORGE_ISSUE, $related->id),
            ))->toArray()),
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
