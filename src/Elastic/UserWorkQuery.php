<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use App\Enum\IssueSortEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStateEnum;
use App\Enum\ReviewStatusEnum;

/**
 * Builds the search requests for the issues and Gerrit changes of one person.
 *
 * A person is matched by email address or by name: Forge hides the email address
 * of many authors, and people often use different addresses in Forge and Gerrit.
 */
final readonly class UserWorkQuery
{
    public function __construct(
        private string $email,
        private string $name,
        private bool $includeClosed = false,
        private int $size = 100,
        private ?IssueStatusEnum $issueStatus = null,
        private ?ReviewStateEnum $reviewState = null,
        private IssueSortEnum $issueSort = IssueSortEnum::UPDATED,
    ) {
    }

    /**
     * Issues the person reported or is assigned to.
     *
     * The states are counted over all issues of the person, so the hidden closed ones can be offered, too.
     * Hiding the closed issues and the selected state only narrow down the listed issues.
     * They are listed in the given order, the Gerrit changes always by their last update.
     */
    public function issues(): array
    {
        $request = $this->request([
            ['author.email' => $this->emails()],
            ['author.name' => $this->names()],
            ['assigned_to.name.keyword' => $this->names()],
        ]);
        $request['sort'] = $this->issueSort->sort();
        $request['aggs'] = [
            'status' => ['terms' => ['field' => 'status.name', 'size' => 20]],
        ];

        return $this->withPostFilter(
            $request,
            ['terms' => ['status.name' => IssueStatusEnum::closedLabels()]],
            null === $this->issueStatus ? null : ['term' => ['status.name' => $this->issueStatus->label()]],
        );
    }

    /**
     * Gerrit changes the person is the commit author of, counted like the issues.
     */
    public function reviews(): array
    {
        $request = $this->request([
            ['ownerEmail' => $this->emails()],
            ['owner' => $this->names()],
        ]);
        $states = [];
        foreach (ReviewStateEnum::cases() as $state) {
            $states[$state->value] = $state->query();
        }
        $request['aggs'] = [
            'status' => ['filters' => ['filters' => $states]],
            // Open changes that need a rebase, counted apart: they are ready for review or work in progress, too
            'conflicts' => ['filter' => ['bool' => ['filter' => [
                ['term' => ['reviewstatus' => ReviewStatusEnum::NEW->value]],
                ['term' => ['mergeable' => 'no']],
            ]]]],
        ];

        return $this->withPostFilter(
            $request,
            ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]],
            $this->reviewState?->query(),
        );
    }

    /**
     * Counts the open issues of the person, without listing them.
     */
    public function openIssuesCount(): array
    {
        return $this->openCount($this->issues(), ['terms' => ['status.name' => IssueStatusEnum::closedLabels()]]);
    }

    /**
     * Counts the open Gerrit changes of the person, the ones in progress included, without listing them.
     */
    public function openReviewsCount(): array
    {
        return $this->openCount($this->reviews(), ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]]);
    }

    /**
     * @param array<string, mixed> $request a request of issues() or reviews()
     * @param array<string, mixed> $closed  matches the closed documents
     */
    private function openCount(array $request, array $closed): array
    {
        return ['query' => ['bool' => ['must' => [$request['query']], 'must_not' => [$closed]]]];
    }

    /**
     * @param list<array<string, list<string>>> $identityTerms field => values, one of them has to match
     */
    private function request(array $identityTerms): array
    {
        $should = [];
        foreach ($identityTerms as $term) {
            $values = current($term);
            if ([] !== $values) {
                $should[] = ['terms' => $term];
            }
        }
        $bool = [] === $should
            // Without email and name nothing can belong to the person
            ? ['must_not' => [['match_all' => new \stdClass()]]]
            : ['should' => $should, 'minimum_should_match' => 1];

        return [
            'query' => ['bool' => $bool],
            'sort' => ['updated_on' => 'desc'],
            'size' => $this->size,
            'track_total_hits' => true,
        ];
    }

    /**
     * Narrows down the listed documents without changing the counts of the aggregations.
     *
     * @param array<string, mixed>      $closed   matches the closed documents
     * @param array<string, mixed>|null $selected matches the selected state
     */
    private function withPostFilter(array $request, array $closed, ?array $selected): array
    {
        $bool = [];
        if (!$this->includeClosed) {
            $bool['must_not'] = [$closed];
        }
        if (null !== $selected) {
            $bool['filter'] = [$selected];
        }
        if ([] !== $bool) {
            $request['post_filter'] = ['bool' => $bool];
        }

        return $request;
    }

    /**
     * Keyword fields are case-sensitive, so the address is also tried in lower case.
     *
     * @return list<string>
     */
    private function emails(): array
    {
        $email = trim($this->email);
        if ('' === $email) {
            return [];
        }

        return array_values(array_unique([$email, mb_strtolower($email)]));
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        $name = trim($this->name);

        return '' === $name ? [] : [$name];
    }
}
