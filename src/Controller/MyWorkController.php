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
use App\Enum\IssueStatusEnum;
use App\Enum\ReviewStateEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use T3G\Bundle\Keycloak\Security\KeyCloakUser;

/**
 * Personal area: the issues and Gerrit changes of the logged-in user.
 */
#[Route(path: '/my', name: 'my_')]
#[IsGranted('ROLE_USER')]
class MyWorkController extends AbstractController
{
    #[Route(name: 'index')]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $email = $user instanceof KeyCloakUser ? (string) $user->getEmail() : '';
        $name = $user instanceof KeyCloakUser ? (string) $user->getDisplayName() : '';
        // The status is given by its Forge id, anything else is ignored
        $statusId = filter_var($request->query->getString('status'), FILTER_VALIDATE_INT);
        $issueStatus = false === $statusId ? null : IssueStatusEnum::tryFrom($statusId);
        $reviewState = ReviewStateEnum::tryFrom($request->query->getString('review'));
        // A closed state can only be found among all issues and changes
        $includeClosed = 'all' === $request->query->getString('state')
            || (bool) $issueStatus?->isClosed()
            || (bool) $reviewState?->isClosed();

        $query = new UserWorkQuery($email, $name, $includeClosed, issueStatus: $issueStatus, reviewState: $reviewState);
        $reviews = ElasticUtility::getIndexByName('review')->createSearch($query->reviews())->search();
        $issues = ElasticUtility::getIndexByName('issue')->createSearch($query->issues())->search();
        $reviewStateCounts = [];
        foreach ($reviews->getAggregation('status')['buckets'] ?? [] as $state => $bucket) {
            if ((int) $bucket['doc_count'] > 0) {
                $reviewStateCounts[(string) $state] = (int) $bucket['doc_count'];
            }
        }
        // In the order of the workflow, not by count
        $statusCounts = [];
        foreach ($issues->getAggregation('status')['buckets'] ?? [] as $bucket) {
            $statusCounts[(string) $bucket['key']] = (int) $bucket['doc_count'];
        }
        $statusCounts = $this->sortByOrder($statusCounts, self::issueStatusOrder());
        $reviewStateCounts = $this->sortByOrder($reviewStateCounts, self::reviewStateOrder());

        $reviewGroups = $this->group(
            array_map(static fn ($result): array => $result->getData(), $reviews->getResults()),
            static fn (array $change): string => ReviewStateEnum::fromChange($change)->value,
            self::reviewStateOrder(),
        );
        $issueGroups = $this->group(
            array_map(static fn ($result): array => $result->getData(), $issues->getResults()),
            static fn (array $issue): string => (string) ($issue['status']['name'] ?? ''),
            self::issueStatusOrder(),
        );

        return $this->render('my/index.html.twig', [
            'email' => $email,
            'name' => $name,
            'includeClosed' => $includeClosed,
            'reviewGroups' => $reviewGroups,
            'reviewsShown' => count($reviews->getResults()),
            'reviewsTotal' => $reviews->getTotalHits(),
            'reviewState' => $reviewState?->value,
            'reviewStateLabels' => array_combine(self::reviewStateOrder(), array_map(static fn (ReviewStateEnum $state): string => $state->label(), ReviewStateEnum::cases())),
            'finishedReviewStates' => array_values(array_map(static fn (ReviewStateEnum $state): string => $state->value, array_filter(ReviewStateEnum::cases(), static fn (ReviewStateEnum $state): bool => $state->isClosed()))),
            'reviewStateCounts' => $reviewStateCounts,
            'reviewStateTotal' => array_sum($reviewStateCounts),
            'conflictCount' => (int) ($reviews->getAggregation('conflicts')['doc_count'] ?? 0),
            'issueGroups' => $issueGroups,
            'issuesShown' => count($issues->getResults()),
            'issuesTotal' => $issues->getTotalHits(),
            'issueStatus' => $issueStatus?->label(),
            'issueStatusId' => $issueStatus?->value,
            // Name => Forge id, for the links of the filter
            'issueStatusIds' => array_combine(self::issueStatusOrder(), array_map(static fn (IssueStatusEnum $state): int => $state->value, IssueStatusEnum::cases())),
            'issueStatusCounts' => $statusCounts,
            'issueStatusTotal' => array_sum($statusCounts),
            'finishedIssueStates' => IssueStatusEnum::closedLabels(),
        ]);
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
