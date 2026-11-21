<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Api;

use App\Enum\IssueStateFilterEnum;
use Symfony\Component\HttpFoundation\Request;

/**
 * The filters of /api/v1/issues besides the search words, see ApiIssueQuery.
 *
 * People are filtered by their number on Forge or by words of their name, see PersonFilter.
 * The days are whole days in UTC, "since" includes the day and "before" leaves it out.
 */
final readonly class IssueFilters
{
    /**
     * @param string|null       $tracker   the name of a tracker, like "Bug"
     * @param string|null       $category  the name of a category, like "Backend User Interface"
     * @param PersonFilter|null $author    who reported the issues
     * @param PersonFilter|null $assignee  who the issues are assigned to
     * @param PersonFilter|null $involving who is on either side
     */
    public function __construct(
        public IssueStateFilterEnum $state = IssueStateFilterEnum::OPEN,
        public ?string $tracker = null,
        public ?string $category = null,
        public ?PersonFilter $author = null,
        public ?PersonFilter $assignee = null,
        public ?PersonFilter $involving = null,
        public ?\DateTimeImmutable $createdSince = null,
        public ?\DateTimeImmutable $createdBefore = null,
        public ?\DateTimeImmutable $updatedSince = null,
        public ?\DateTimeImmutable $updatedBefore = null,
    ) {
    }

    /**
     * @throws InvalidParameter
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            state: ListParameters::choice($request, 'status', IssueStateFilterEnum::class, IssueStateFilterEnum::OPEN),
            tracker: ListParameters::optional($request, 'tracker'),
            category: ListParameters::optional($request, 'category'),
            author: ListParameters::person($request, 'author'),
            assignee: ListParameters::person($request, 'assignee'),
            involving: ListParameters::person($request, 'involving'),
            createdSince: ListParameters::day($request, 'createdSince'),
            createdBefore: ListParameters::day($request, 'createdBefore'),
            updatedSince: ListParameters::day($request, 'updatedSince'),
            updatedBefore: ListParameters::day($request, 'updatedBefore'),
        );
    }
}
