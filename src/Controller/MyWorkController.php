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
use App\People\User;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Search\IssueStatusCount;
use App\Search\UserWorkQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use T3G\Bundle\Keycloak\Security\KeyCloakUser;

/**
 * Personal area: the Gerrit changes and the issues of the logged-in user, each in a view of its own.
 */
#[Route(path: '/my', name: 'my_')]
#[IsGranted('ROLE_USER')]
class MyWorkController extends AbstractController
{
    public function __construct(
        private readonly ChangeRepository $changes,
        private readonly IssueRepository $issues,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Links to /my keep working: one with an issue state opens the issues, everything else the changes.
     */
    #[Route(name: 'index')]
    public function index(Request $request): Response
    {
        $parameters = $request->query->all();
        if ($request->query->has('status')) {
            return $this->redirectToRoute('my_issues', $parameters);
        }

        return $this->redirectToRoute('my_changes', $parameters);
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

    private function query(bool $includeClosed, string $searchTerms, ?IssueStatusEnum $issueStatus = null, ?ReviewStateEnum $reviewState = null, IssueSortEnum $issueSort = IssueSortEnum::UPDATED, ReviewSortEnum $reviewSort = ReviewSortEnum::UPDATED): UserWorkQuery
    {
        $user = $this->getUser();
        // The people of the account on Forge and Gerrit, see UserRepository
        $accounts = $user instanceof KeyCloakUser ? $this->users->accountsOf($user->getUserIdentifier(), (string) $user->getEmail()) : [];

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
        ];
        if ('changes' === $view) {
            $data['openIssues'] = $this->issues->count($query->openIssuesCount());
        } else {
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
