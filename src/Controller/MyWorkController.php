<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Elastic\ElasticUtility;
use App\Elastic\UserWorkQuery;
use App\Enum\IssueSortEnum;
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStateEnum;
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
        $query = $this->query($includeClosed, reviewState: $reviewState);
        $reviews = ElasticUtility::getIndexByName('review')->createSearch($query->reviews())->search();

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
            ...$this->viewData($query, 'changes', $includeClosed),
            'openChanges' => array_sum(array_diff_key($counts, array_flip($closedStates))),
            'groups' => $this->group(
                array_map(static fn ($result): array => $result->getData(), $reviews->getResults()),
                static fn (array $change): string => ReviewStateEnum::fromChange($change)->value,
                self::reviewStateOrder(),
            ),
            'shown' => count($reviews->getResults()),
            'total' => $reviews->getTotalHits(),
            'selected' => $reviewState?->value,
            'labels' => array_combine(self::reviewStateOrder(), array_map(static fn (ReviewStateEnum $state): string => $state->label(), ReviewStateEnum::cases())),
            'counts' => $counts,
            'closedStates' => $closedStates,
            'conflictCount' => (int) ($reviews->getAggregation('conflicts')['doc_count'] ?? 0),
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
        // No search words rank the issues, so they cannot be sorted by relevance
        $sort = IssueSortEnum::fromRequest($request->query->getString('sort'), false);
        $query = $this->query($includeClosed, issueStatus: $issueStatus, issueSort: $sort);
        $issues = ElasticUtility::getIndexByName('issue')->createSearch($query->issues())->search();

        $counts = [];
        foreach ($issues->getAggregation('status')['buckets'] ?? [] as $bucket) {
            $counts[(string) $bucket['key']] = (int) $bucket['doc_count'];
        }
        // In the order of the workflow, not by count
        $counts = $this->sortByOrder($counts, self::issueStatusOrder());

        return $this->render('my/issues.html.twig', [
            ...$this->viewData($query, 'issues', $includeClosed),
            'openIssues' => array_sum(array_diff_key($counts, array_flip(IssueStatusEnum::closedLabels()))),
            'groups' => $this->group(
                array_map(static fn ($result): array => $result->getData(), $issues->getResults()),
                static fn (array $issue): string => (string) ($issue['status']['name'] ?? ''),
                self::issueStatusOrder(),
            ),
            'shown' => count($issues->getResults()),
            'total' => $issues->getTotalHits(),
            'selected' => $issueStatus?->label(),
            'selectedId' => $issueStatus?->value,
            // Name => Forge id, for the links of the filter
            'statusIds' => array_combine(self::issueStatusOrder(), array_map(static fn (IssueStatusEnum $state): int => $state->value, IssueStatusEnum::cases())),
            'counts' => $counts,
            'closedStates' => IssueStatusEnum::closedLabels(),
            'sort' => $sort,
            'sortOptions' => IssueSortEnum::options(false),
            'isDefaultSort' => IssueSortEnum::default(false) === $sort,
        ]);
    }

    private function query(bool $includeClosed, ?IssueStatusEnum $issueStatus = null, ?ReviewStateEnum $reviewState = null, IssueSortEnum $issueSort = IssueSortEnum::UPDATED): UserWorkQuery
    {
        $user = $this->getUser();

        return new UserWorkQuery(
            $user instanceof KeyCloakUser ? (string) $user->getEmail() : '',
            $user instanceof KeyCloakUser ? (string) $user->getDisplayName() : '',
            $includeClosed,
            issueStatus: $issueStatus,
            reviewState: $reviewState,
            issueSort: $issueSort,
        );
    }

    /**
     * What both views show: who the work is found for, and the open count of the other view for its tab.
     *
     * @return array<string, mixed>
     */
    private function viewData(UserWorkQuery $query, string $view, bool $includeClosed): array
    {
        $user = $this->getUser();
        $data = [
            'view' => $view,
            'email' => $user instanceof KeyCloakUser ? (string) $user->getEmail() : '',
            'name' => $user instanceof KeyCloakUser ? (string) $user->getDisplayName() : '',
            'includeClosed' => $includeClosed,
        ];
        if ('changes' === $view) {
            $data['openIssues'] = ElasticUtility::getIndexByName('issue')->count($query->openIssuesCount());
        } else {
            $data['openChanges'] = ElasticUtility::getIndexByName('review')->count($query->openReviewsCount());
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
     * Groups the documents by state, the groups in the given order and unknown states last.
     *
     * @param list<array<string, mixed>>             $documents
     * @param callable(array<string, mixed>): string $stateOf
     * @param list<string>                           $order
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function group(array $documents, callable $stateOf, array $order): array
    {
        $groups = [];
        foreach ($documents as $document) {
            $groups[$stateOf($document)][] = $document;
        }

        return $this->sortByOrder($groups, $order);
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
