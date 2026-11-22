<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Enum\IssueSortEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewSortEnum;
use App\Enum\ReviewStateEnum;
use App\Enum\WaitingForEnum;
use App\People\Following;
use App\People\InterestSelection;
use App\People\User;
use App\People\UserDirectory;
use App\Repository\ChangeRepository;
use App\Repository\FollowRepository;
use App\Repository\InterestRepository;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Search\IssueStatusCount;
use App\Search\OverviewQuery;
use App\Search\UserWorkQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use T3G\Bundle\Keycloak\Security\KeyCloakUser;

/**
 * Personal area: an overview of what waits for the logged-in user, the Gerrit changes and the issues of the user,
 * each in a view of its own, the activity of the people the user follows, and the statistics of the user.
 */
#[Route(path: '/my', name: 'my_')]
#[IsGranted('ROLE_USER')]
class MyWorkController extends AbstractController
{
    /**
     * The entries of each short list of the overview. "Show all" opens the search with all of them.
     */
    private const int SHORT = 5;

    /**
     * The entries of each list of Following, the page of the work of the people followed.
     */
    private const int LONG = 10;

    public function __construct(
        private readonly ChangeRepository $changes,
        private readonly IssueRepository $issues,
        private readonly UserRepository $users,
        private readonly FollowRepository $follows,
        private readonly Following $following,
        private readonly InterestRepository $interests,
        private readonly UserDirectory $people,
    ) {
    }

    /**
     * Links to /my keep working: one with an issue state opens the issues, one with other parameters the changes.
     * Without parameters it is the overview.
     */
    #[Route(name: 'index')]
    public function index(Request $request): Response
    {
        $parameters = $request->query->all();
        if ($request->query->has('status')) {
            return $this->redirectToRoute('my_issues', $parameters);
        }
        if ([] !== $parameters) {
            return $this->redirectToRoute('my_changes', $parameters);
        }

        return $this->redirectToRoute('my_overview');
    }

    /**
     * What waits for the user, a few of each: the own changes with a negative vote or a conflict and the issues
     * assigned, the changes to review and the new issues of all interests of the user, and the changes of the
     * people followed that need help. Each list links to all of it.
     */
    #[Route(path: '/overview', name: 'overview')]
    public function overview(): Response
    {
        $interests = $this->interests->ofOwner($this->follower());
        $selection = InterestSelection::of('', $interests);
        $followed = $this->follows->peopleOf($this->follower());
        $query = $this->query(false, '');
        $now = new \DateTimeImmutable();

        return $this->render('my/overview.html.twig', [
            ...$this->viewData($query, 'overview', false, ''),
            'openChanges' => $this->changes->count($query->openReviewsCount()),
            'openIssues' => $this->issues->count($query->openIssuesCount()),
            'waitingChanges' => $this->changes->search($query->changesWaitingForOwner(self::SHORT)),
            'assignedIssues' => $this->issues->search($query->assignedIssues(self::SHORT)),
            'interests' => $interests,
            'selection' => $selection,
            ...$this->interestLists($selection, $now, self::SHORT),
            ...$this->helpLists($followed, $now, self::SHORT),
            'followed' => $followed,
        ]);
    }

    /**
     * The changes to review and the new issues of the interests of the user: all of them, one, or everything.
     * InterestController adds and changes the interests.
     */
    #[Route(path: '/interests', name: 'interests')]
    public function interests(Request $request): Response
    {
        $interests = $this->interests->ofOwner($this->follower());
        $selection = InterestSelection::of($request->query->getString('interest'), $interests);
        $query = $this->query(false, '');

        return $this->render('my/interests.html.twig', [
            ...$this->viewData($query, 'interests', false, ''),
            'openChanges' => $this->changes->count($query->openReviewsCount()),
            'openIssues' => $this->issues->count($query->openIssuesCount()),
            'interests' => $interests,
            'selection' => $selection,
            ...$this->interestLists($selection, new \DateTimeImmutable(), OverviewQuery::SIZE),
            'maxInterests' => InterestRepository::LIMIT,
        ]);
    }

    #[Route(path: '/changes', name: 'changes')]
    public function changes(Request $request): Response
    {
        $reviewState = ReviewStateEnum::tryFrom($request->query->getString('review'));
        // A closed state can only be found among all changes
        $includeClosed = 'all' === $request->query->getString('state') || (bool) $reviewState?->isClosed();
        $searchTerms = trim($request->query->getString('query'));
        // Only search words rank the changes, so only then they can be sorted by relevance
        $sort = ReviewSortEnum::fromRequest($request->query->getString('sort'), '' !== $searchTerms);
        $query = $this->query($includeClosed, $searchTerms, reviewState: $reviewState, reviewSort: $sort);
        $changes = $this->changes->search($query->reviews());

        $counts = array_filter(
            $changes->counts('status'),
            static fn (int $count): bool => $count > 0,
        );
        $counts = $this->sortByOrder($counts, self::reviewStateOrder());
        $closedStates = array_values(array_map(
            static fn (ReviewStateEnum $state): string => $state->value,
            array_filter(ReviewStateEnum::cases(), static fn (ReviewStateEnum $state): bool => $state->isClosed()),
        ));

        return $this->render('my/changes.html.twig', [
            ...$this->viewData($query, 'changes', $includeClosed, $searchTerms),
            'openChanges' => array_sum(array_diff_key($counts, array_flip($closedStates))),
            'changes' => $changes,
            'shown' => count($changes),
            'total' => $changes->total,
            'selected' => $reviewState?->value,
            'labels' => array_combine(self::reviewStateOrder(), array_map(static fn (ReviewStateEnum $state): string => $state->label(), ReviewStateEnum::cases())),
            'counts' => $counts,
            'closedStates' => $closedStates,
            'sort' => $sort,
            'sortOptions' => ReviewSortEnum::options('' !== $searchTerms),
            'isDefaultSort' => ReviewSortEnum::default('' !== $searchTerms) === $sort,
        ]);
    }

    #[Route(path: '/issues', name: 'issues')]
    public function issues(Request $request): Response
    {
        // The status is given by its Forge id, anything else is ignored
        $statusId = filter_var($request->query->getString('status'), FILTER_VALIDATE_INT);
        $issueStatus = false === $statusId ? null : IssueStatusEnum::tryFrom($statusId);
        // A closed state can only be found among all issues
        $includeClosed = 'all' === $request->query->getString('state') || (bool) $issueStatus?->isClosed();
        $searchTerms = trim($request->query->getString('query'));
        // Only search words rank the issues, so only then they can be sorted by relevance
        $sort = IssueSortEnum::fromRequest($request->query->getString('sort'), '' !== $searchTerms);
        $query = $this->query($includeClosed, $searchTerms, issueStatus: $issueStatus, issueSort: $sort);
        $issues = $this->issues->search($query->issues());

        $counts = $issues->counts('status');
        $statusCounts = IssueStatusCount::listOf($counts);
        $closed = array_sum(array_map(
            static fn (IssueStatusCount $item): int => $item->status->isClosed() ? $item->count : 0,
            $statusCounts,
        ));
        $hidden = $includeClosed ? 0 : $closed;

        return $this->render('my/issues.html.twig', [
            ...$this->viewData($query, 'issues', $includeClosed, $searchTerms),
            'openIssues' => array_sum($counts) - $closed,
            'issues' => $issues,
            'shown' => count($issues),
            'total' => $issues->total,
            'selected' => $issueStatus,
            'statusCounts' => $statusCounts,
            'allCount' => array_sum($counts) - $hidden,
            'hidden' => $hidden,
            'sort' => $sort,
            'sortOptions' => IssueSortEnum::options('' !== $searchTerms),
            'isDefaultSort' => IssueSortEnum::default('' !== $searchTerms) === $sort,
        ]);
    }

    /**
     * The work of the people the user follows: where it needs help, and what they did in the last 30 days.
     * FollowController::manage() adds and removes the people.
     */
    #[Route(path: '/following', name: 'following')]
    public function following(): Response
    {
        $query = $this->query(false, '');
        $followed = $this->follows->peopleOf($this->follower());

        return $this->render('my/following.html.twig', [
            ...$this->viewData($query, 'following', false, ''),
            'openChanges' => $this->changes->count($query->openReviewsCount()),
            'openIssues' => $this->issues->count($query->openIssuesCount()),
            ...$this->helpLists($followed, new \DateTimeImmutable(), self::LONG),
            'followed' => $followed,
        ]);
    }

    /**
     * The statistics of the user: the board of the statistics for the accounts of the user on Forge and Gerrit, see
     * templates/stream/_frame.html.twig. Without an account it is empty.
     */
    #[Route(path: '/statistics', name: 'statistics')]
    public function statistics(): Response
    {
        $query = $this->query(false, '');

        return $this->render('my/statistics.html.twig', [
            ...$this->viewData($query, 'statistics', false, ''),
            'openChanges' => $this->changes->count($query->openReviewsCount()),
            'openIssues' => $this->issues->count($query->openIssuesCount()),
            'ownPeople' => $this->ownPeople(),
        ]);
    }

    /**
     * The keys of the accounts of the user in the filters of people. The index may not have joined the account on
     * Forge with the one on Gerrit, so there can be two.
     *
     * @return list<string>
     */
    private function ownPeople(): array
    {
        $keys = [];
        foreach ($this->accounts() as $account) {
            if (null !== $account->forgeId) {
                $keys[] = $this->people->keyOf('forge', $account->forgeId);
            }
            if (null !== $account->gerritId) {
                $keys[] = $this->people->keyOf('gerrit', $account->gerritId);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The lists of the interests in the selection, each with the filters of the search that shows all of it.
     *
     * @return array<string, mixed>
     */
    private function interestLists(InterestSelection $selection, \DateTimeImmutable $now, int $size): array
    {
        return [
            'reviewable' => $this->changes->search(
                OverviewQuery::reviewable($this->gerritIds(), $selection->paths, $size),
            ),
            'reviewableFilters' => OverviewQuery::reviewableFilters($selection->paths),
            'newIssues' => $this->issues->search(OverviewQuery::newIssues($selection->categories, $now, $size)),
            'newIssuesFilters' => OverviewQuery::newIssuesFilters($selection->categories, $now),
            'newDays' => OverviewQuery::NEW_DAYS,
        ];
    }

    /**
     * The work of the people followed that needs help, each list with the filters of the search that shows all of
     * it. Without people the lists are empty and not asked for.
     *
     * @param list<string> $followed the keys of the people
     *
     * @return array<string, mixed>
     */
    private function helpLists(array $followed, \DateTimeImmutable $now, int $size): array
    {
        if ([] === $followed) {
            return ['help' => null];
        }

        return ['help' => [
            'owner' => $this->following->waitingChanges($followed, WaitingForEnum::OWNER, $size),
            'ownerFilters' => OverviewQuery::peopleChangesFilters($followed, WaitingForEnum::OWNER),
            'review' => $this->following->waitingChanges($followed, WaitingForEnum::FIRST_REVIEW, $size),
            'reviewFilters' => OverviewQuery::peopleChangesFilters($followed, WaitingForEnum::FIRST_REVIEW),
            'issues' => $this->following->unansweredIssues($followed, $now, $size),
            'issuesFilters' => OverviewQuery::unansweredIssuesFilters($followed, $now),
            'issueDays' => OverviewQuery::UNANSWERED_DAYS,
        ]];
    }

    /**
     * The accounts of the user on Gerrit.
     *
     * @return list<int>
     */
    private function gerritIds(): array
    {
        return array_values(array_filter(array_map(
            static fn (User $account): ?int => $account->gerritId,
            $this->accounts(),
        )));
    }

    /**
     * The people of the account on Forge and Gerrit, see UserRepository.
     *
     * @return list<User>
     */
    private function accounts(): array
    {
        $user = $this->getUser();

        return $user instanceof KeyCloakUser
            ? $this->users->accountsOf($user->getUserIdentifier(), (string) $user->getEmail())
            : [];
    }

    private function follower(): string
    {
        return (string) $this->getUser()?->getUserIdentifier();
    }

    private function query(bool $includeClosed, string $searchTerms, ?IssueStatusEnum $issueStatus = null, ?ReviewStateEnum $reviewState = null, IssueSortEnum $issueSort = IssueSortEnum::UPDATED, ReviewSortEnum $reviewSort = ReviewSortEnum::UPDATED): UserWorkQuery
    {
        $user = $this->getUser();
        $accounts = $this->accounts();

        return new UserWorkQuery(
            $user instanceof KeyCloakUser ? (string) $user->getEmail() : '',
            $user instanceof KeyCloakUser ? (string) $user->getDisplayName() : '',
            $includeClosed,
            issueStatus: $issueStatus,
            reviewState: $reviewState,
            issueSort: $issueSort,
            reviewSort: $reviewSort,
            searchTerms: $searchTerms,
            forgeIds: array_values(array_filter(array_map(static fn (User $account): ?int => $account->forgeId, $accounts))),
            gerritIds: array_values(array_filter(array_map(static fn (User $account): ?int => $account->gerritId, $accounts))),
        );
    }

    /**
     * What both views show: who the work is found for, the search words, and the open count of the other view for its
     * tab, for the same words.
     *
     * @return array<string, mixed>
     */
    private function viewData(UserWorkQuery $query, string $view, bool $includeClosed, string $searchTerms): array
    {
        $user = $this->getUser();
        $data = [
            'view' => $view,
            'email' => $user instanceof KeyCloakUser ? (string) $user->getEmail() : '',
            'name' => $user instanceof KeyCloakUser ? (string) $user->getDisplayName() : '',
            'includeClosed' => $includeClosed,
            'query' => $searchTerms,
            'followingCount' => $this->follows->count(['follower' => $this->follower()]),
        ];
        if ('changes' === $view) {
            $data['openIssues'] = $this->issues->count($query->openIssuesCount());
        } elseif ('issues' === $view) {
            $data['openChanges'] = $this->changes->count($query->openReviewsCount());
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private static function reviewStateOrder(): array
    {
        return array_map(static fn (ReviewStateEnum $state): string => $state->value, ReviewStateEnum::cases());
    }

    /**
     * @template T
     *
     * @param array<string, T> $values
     * @param list<string>     $order
     *
     * @return array<string, T>
     */
    private function sortByOrder(array $values, array $order): array
    {
        $position = array_flip($order);
        uksort($values, static fn (string $a, string $b): int => ($position[$a] ?? PHP_INT_MAX) <=> ($position[$b] ?? PHP_INT_MAX));

        return $values;
    }
}
