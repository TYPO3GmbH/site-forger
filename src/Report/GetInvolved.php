<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report;

use App\Document\Change;
use App\Document\Issue;
use App\Elastic\SearchResult;
use App\Enum\IssueMarkEnum;
use App\Enum\IssueSortEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewSizeEnum;
use App\Enum\ReviewStatusEnum;
use App\Enum\TrackerEnum;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Search\IssueMarkQuery;
use App\Search\IssueSortQuery;
use App\Search\ReviewLists;
use App\Search\ReviewSizeQuery;

/**
 * The short lists of the page "Get involved": what somebody new to the TYPO3 Core can do without experience. Each
 * list counts all of its documents and has the first few, like the lists of the dashboard. Its link in the template
 * leads to the list or search with the same filter.
 */
final readonly class GetInvolved
{
    public const int LIST_SIZE = 5;

    /**
     * The people the page shows on top: as many as the places of their faces, see _get-involved.scss.
     */
    public const int PEOPLE_SIZE = 9;

    /**
     * The merges the people are taken from, at most: enough for nine different people on any day.
     */
    private const int MERGES_FOR_PEOPLE = 20;

    public function __construct(
        private IssueRepository $issues,
        private ChangeRepository $changes,
    ) {
    }

    /**
     * The small changes ready for review, the size Easy of the list "Ready for review", updated last first: their
     * owners are still at work on them.
     *
     * @return SearchResult<Change>
     */
    public function smallReviews(): SearchResult
    {
        $query = ['bool' => ['filter' => [
            (array) ReviewLists::query(ReviewLists::READY),
            ReviewSizeQuery::changes(ReviewSizeEnum::EASY),
        ]]];

        return $this->changes->search(self::request($query, [['updated_on' => 'desc']]));
    }

    /**
     * The people behind the changes merged last, each once, in the order of the merges: of each change first its
     * author, then its reviewers, see Change::reviewers(). A backport counts with its original, as on the dashboard.
     * No ranking: who comes first merged last.
     *
     * @return list<MergePerson>
     */
    public function recentPeople(): array
    {
        $merged = $this->changes->search([
            'query' => ['term' => ['reviewstatus' => ReviewStatusEnum::MERGED->value]],
            'sort' => [['updated_on' => 'desc']],
            'collapse' => ['field' => 'change_id'],
            'size' => self::MERGES_FOR_PEOPLE,
        ]);
        $people = [];
        foreach ($merged as $change) {
            $owner = $change->ownerPerson();
            $team = null === $owner ? [] : [new MergePerson($owner, $change, true)];
            foreach ($change->reviewers() as $reviewer) {
                $team[] = new MergePerson($reviewer, $change, false);
            }
            foreach ($team as $mergePerson) {
                $people[$mergePerson->person->name] ??= $mergePerson;
            }
            if (count($people) >= self::PEOPLE_SIZE) {
                break;
            }
        }

        return array_slice(array_values($people), 0, self::PEOPLE_SIZE);
    }

    /**
     * The good first issues, updated last first.
     *
     * @return SearchResult<Issue>
     */
    public function goodFirstIssues(): SearchResult
    {
        return $this->markedIssues(IssueMarkEnum::GOOD_FIRST_ISSUE);
    }

    /**
     * The bugs nobody looked at yet, the status "New", the newest first: somebody who reproduces one helps the
     * Core team to triage it.
     *
     * @return SearchResult<Issue>
     */
    public function newBugs(): SearchResult
    {
        $query = ['bool' => ['filter' => [
            ['term' => ['tracker.id' => TrackerEnum::BUG->value]],
            ['term' => ['status.id' => IssueStatusEnum::NEW->value]],
        ]]];

        return $this->issues->search(self::request($query, IssueSortQuery::sort(IssueSortEnum::NEWEST)));
    }

    /**
     * @return SearchResult<Issue>
     */
    private function markedIssues(IssueMarkEnum $mark): SearchResult
    {
        $request = self::request(IssueMarkQuery::issues($mark), IssueSortQuery::sort(IssueSortEnum::UPDATED));

        return $this->issues->search($request);
    }

    /**
     * @param array<string, mixed> $query
     * @param list<mixed>          $sort
     *
     * @return array<string, mixed>
     */
    private static function request(array $query, array $sort): array
    {
        return ['query' => $query, 'sort' => $sort, 'size' => self::LIST_SIZE, 'track_total_hits' => true];
    }
}
