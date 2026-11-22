<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Elastic\SearchResult;
use App\Enum\WaitingForEnum;
use App\Repository\ChangeRepository;
use App\Repository\FollowRepository;
use App\Repository\InterestRepository;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Search\OverviewQuery;
use App\Search\UserWorkQuery;

/**
 * What waits in My Work of a user, with the queries of the lists of the overview: the dashboard shows the numbers
 * and the first rows, the overview the lists.
 */
final readonly class PersonalWork
{
    /**
     * The rows of a card of the dashboard, see PersonalRows. The lists of My Work show more.
     */
    public const int ROWS = 3;

    public function __construct(
        private UserRepository $users,
        private ChangeRepository $changes,
        private IssueRepository $issues,
        private InterestRepository $interests,
        private FollowRepository $follows,
        private Following $following,
    ) {
    }

    /**
     * @param string $username the username of the TYPO3 account, which owns the interests and the follows
     */
    public function figures(string $username, string $email, string $name, \DateTimeImmutable $now): PersonalFigures
    {
        $accounts = $this->users->accountsOf($username, $email);
        $gerritIds = self::numbers($accounts, static fn (User $account): ?int => $account->gerritId);
        $own = new UserWorkQuery(
            $email,
            $name,
            forgeIds: self::numbers($accounts, static fn (User $account): ?int => $account->forgeId),
            gerritIds: $gerritIds,
        );
        $interests = $this->interests->ofOwner($username);
        $selection = InterestSelection::of('', $interests);
        $followed = $this->follows->peopleOf($username);
        $none = SearchResult::ofDocuments([], 0);

        // A card leaves out what a card before it shows, so its lists load more than its rows
        $rows = new PersonalRows(self::ROWS);
        $waitingChanges = $this->changes->search($own->changesWaitingForOwner($rows->candidates()));
        $assignedIssues = $this->issues->search($own->assignedIssues($rows->candidates()));
        $waitingRows = $rows->take($waitingChanges, $assignedIssues);

        $size = $rows->candidates();
        $reviewable = [] === $interests ? $none : $this->changes->search(
            OverviewQuery::reviewable($gerritIds, $selection->paths, $size),
        );
        $newIssues = [] === $interests ? $none : $this->issues->search(
            OverviewQuery::newIssues($selection->categories, $now, $size),
        );
        $interestRows = $rows->take($reviewable, $newIssues);

        $size = $rows->candidates();
        $stuck = [] === $followed ? $none : $this->following->waitingChanges(
            $followed,
            WaitingForEnum::OWNER,
            $size,
        );
        $unreviewed = [] === $followed ? $none : $this->following->waitingChanges(
            $followed,
            WaitingForEnum::FIRST_REVIEW,
            $size,
        );
        $unanswered = [] === $followed ? $none : $this->following->unansweredIssues($followed, $now, $size);

        return new PersonalFigures(
            $waitingChanges->total,
            $assignedIssues->total,
            count($interests),
            $reviewable->total,
            $newIssues->total,
            count($followed),
            $stuck->total,
            $unreviewed->total,
            $unanswered->total,
            $waitingRows,
            $interestRows,
            $rows->take($stuck, $unreviewed, $unanswered),
        );
    }

    /**
     * @param list<User>           $accounts
     * @param \Closure(User): ?int $number
     *
     * @return list<int>
     */
    private static function numbers(array $accounts, \Closure $number): array
    {
        return array_values(array_filter(array_map($number, $accounts)));
    }
}
