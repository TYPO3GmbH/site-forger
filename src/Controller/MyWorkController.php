<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Elastic\Indices;
use App\Enum\IssueSortEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewSortEnum;
use App\Enum\ReviewStateEnum;
use App\People\User;
use App\Repository\ChangeRepository;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
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
        private readonly Indices $indices,
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
        $reviews = $this->indices->reviews()->createSearch($query->reviews())->search();

        $counts = [];
        foreach ($reviews->getAggregation('status')['buckets'] ?? [] as $state => $bucket) {
            if ((int) $bucket['doc_count'] > 0) {
                $counts[(string) $state] = (int) $bucket['doc_count'];
            }
        }
        $counts = $this->sortByOrder($counts, self::reviewStateOrder());
        $closedStates = array_values(array_map(
            static fn (ReviewStateEnum $state): string => $state->value,
            array_filter(ReviewStateEnum::cases(), static fn (ReviewStateEnum $state): bool => $state->isClosed()),
        ));

        return $this->render('my/changes.html.twig', [
            ...$this->viewData($query, 'changes', $includeClosed, $searchTerms),
            'openChanges' => array_sum(array_diff_key($counts, array_flip($closedStates))),
            'changes' => ChangeRepository::fromResultSet($reviews),
            'shown' => count($reviews->getResults()),
            'total' => $reviews->getTotalHits(),
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
        $issues = $this->indices->issues()->createSearch($query->issues())->search();

        $counts = [];
        foreach ($issues->getAggregation('status')['buckets'] ?? [] as $bucket) {
            $counts[(string) $bucket['key']] = (int) $bucket['doc_count'];
        }
        // In the order of the workflow, not by count
        $counts = $this->sortByOrder($counts, self::issueStatusOrder());

        return $this->render('my/issues.html.twig', [
            ...$this->viewData($query, 'issues', $includeClosed, $searchTerms),
            'openIssues' => array_sum(array_diff_key($counts, array_flip(IssueStatusEnum::closedLabels()))),
            'issues' => IssueRepository::fromResultSet($issues),
            'shown' => count($issues->getResults()),
            'total' => $issues->getTotalHits(),
            'selected' => $issueStatus?->label(),
            'selectedId' => $issueStatus?->value,
            // Name => Forge id, for the links of the filter
            'statusIds' => array_combine(self::issueStatusOrder(), array_map(static fn (IssueStatusEnum $state): int => $state->value, IssueStatusEnum::cases())),
            'counts' => $counts,
            'closedStates' => IssueStatusEnum::closedLabels(),
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
            $data['openIssues'] = $this->indices->issues()->count($query->openIssuesCount());
        } else {
            $data['openChanges'] = $this->indices->reviews()->count($query->openReviewsCount());
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
     * @return list<string>
     */
    private static function issueStatusOrder(): array
    {
        return array_map(static fn (IssueStatusEnum $state): string => $state->label(), IssueStatusEnum::cases());
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
