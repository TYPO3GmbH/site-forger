<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\IssueSortEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewSortEnum;
use App\Enum\ReviewStateEnum;
use App\Enum\ReviewStatusEnum;
use App\People\Users;

/**
 * Builds the search requests for the issues and Gerrit changes of one person.
 *
 * A person is matched by email address or by name: Forge hides the email address
 * of many authors, and people often use different addresses in Forge and Gerrit. On Forge by the numbers of the
 * Forge users of the account, see Users::accountsOf(): the issues keep no emails. On Gerrit also by the number of its
 * account.
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
        private ReviewSortEnum $reviewSort = ReviewSortEnum::UPDATED,
        private string $searchTerms = '',
        /** @var list<int> */
        private array $forgeIds = [],
        /** @var list<int> */
        private array $gerritIds = [],
    ) {
    }

    /**
     * Issues the person reported or is assigned to.
     *
     * The states are counted over all issues of the person, so the hidden closed ones can be offered, too.
     * Hiding the closed issues and the selected state only narrow down the listed issues.
     * They are listed in the given order.
     *
     * @return array<string, mixed> the Elasticsearch request
     */
    public function issues(): array
    {
        $request = $this->request([
            ['author.id' => $this->forgeIds],
            ['assigned_to.id' => $this->forgeIds],
            ['author.name' => $this->names()],
            ['assigned_to.name.keyword' => $this->names()],
        ], '' === trim($this->searchTerms) ? null : IssueSearch::query($this->searchTerms, true));
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
     * Gerrit changes the person is the commit author of, counted and sorted like the issues.
     *
     * @return array<string, mixed> the Elasticsearch request
     */
    public function reviews(): array
    {
        $request = $this->request([
            ['owner_id' => $this->gerritIds],
            ['ownerEmail' => $this->emails()],
            ['owner' => $this->names()],
        ], ReviewSearch::query($this->searchTerms));
        $request['sort'] = $this->reviewSort->sort();
        $states = [];
        foreach (ReviewStateEnum::cases() as $state) {
            $states[$state->value] = $state->query();
        }
        $request['aggs'] = [
            'status' => ['filters' => ['filters' => $states]],
        ];

        return $this->withPostFilter(
            $request,
            ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]],
            $this->reviewState?->query(),
        );
    }

    /**
     * Counts the open issues of the person, without listing them.
     *
     * @return array<string, mixed> the Elasticsearch request
     */
    public function openIssuesCount(): array
    {
        return $this->openCount($this->issues(), ['terms' => ['status.name' => IssueStatusEnum::closedLabels()]]);
    }

    /**
     * Counts the open Gerrit changes of the person, the ones in progress included, without listing them.
     *
     * @return array<string, mixed> the Elasticsearch request
     */
    public function openReviewsCount(): array
    {
        return $this->openCount($this->reviews(), ['terms' => ['reviewstatus' => ReviewStatusEnum::closedValues()]]);
    }

    /**
     * @param array<string, mixed> $request a request of issues() or reviews()
     * @param array<string, mixed> $closed  matches the closed documents
     *
     * @return array<string, mixed>
     */
    private function openCount(array $request, array $closed): array
    {
        return ['query' => ['bool' => ['must' => [$request['query']], 'must_not' => [$closed]]]];
    }

    /**
     * @param list<array<string, list<int|string>>> $identityTerms field => values, one of them has to match
     * @param array<string, mixed>|null             $search        the query of the search words, as the search has it
     *
     * @return array<string, mixed>
     */
    private function request(array $identityTerms, ?array $search = null): array
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
        $query = ['bool' => $bool];
        if (null !== $search) {
            // The words narrow down the work of the person, the counts of the states included
            $query = ['bool' => ['filter' => [$query], 'must' => [$search]]];
        }

        return [
            'query' => $query,
            'sort' => ['updated_on' => 'desc'],
            'size' => $this->size,
            'track_total_hits' => true,
        ];
    }

    /**
     * Narrows down the listed documents without changing the counts of the aggregations.
     *
     * @param array<string, mixed>      $request
     * @param array<string, mixed>      $closed   matches the closed documents
     * @param array<string, mixed>|null $selected matches the selected state
     *
     * @return array<string, mixed>
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
