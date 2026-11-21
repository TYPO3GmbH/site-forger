<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\DTO\Pagination;
use App\Enum\IssueSortEnum;
use App\Enum\ReviewSortEnum;
use App\People\UserDirectory;
use App\Report\StaleIssues;
use App\Search\GlobalSearch;
use App\Search\ReviewLists;
use App\Search\ReviewListSearch;
use Elastica\Exception\InvalidException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReviewController extends AbstractController
{
    public function __construct(
        private readonly ReviewListSearch $reviewListSearch,
        private readonly StaleIssues $staleIssues,
        private readonly GlobalSearch $globalSearch,
        private readonly RequestStack $requestStack,
        private readonly UserDirectory $users,
    ) {
    }

    #[Route(path: '/reviews/ready', name: ReviewLists::READY)]
    public function status(): Response
    {
        return $this->listView(
            ReviewLists::READY,
            'Ready for review',
            ReviewLists::aggregations(without: ['Mergeable']),
        );
    }

    #[Route(path: '/reviews/verified', name: ReviewLists::REVIEWED_AND_VERIFIED)]
    public function hasVerifications(): Response
    {
        return $this->listView(
            ReviewLists::REVIEWED_AND_VERIFIED,
            'Reviewed and verified',
            ReviewLists::aggregations(),
        );
    }

    #[Route(path: '/reviews/needs-verification', name: ReviewLists::NEEDS_VERIFICATION)]
    public function missesVerifications(): Response
    {
        return $this->listView(
            ReviewLists::NEEDS_VERIFICATION,
            'Needs verification',
            ReviewLists::aggregations(),
        );
    }

    #[Route(path: '/reviews/needs-review', name: ReviewLists::NEEDS_REVIEW)]
    public function missesReview(): Response
    {
        return $this->listView(
            ReviewLists::NEEDS_REVIEW,
            'Needs review',
            ReviewLists::aggregations(),
        );
    }

    #[Route(path: '/reviews/needs-rebase', name: ReviewLists::NEEDS_REBASE)]
    public function rebase(): Response
    {
        return $this->listView(
            ReviewLists::NEEDS_REBASE,
            'Needs rebase',
            ReviewLists::aggregations(without: ['Mergeable', 'Patchsets', 'Releases', 'Topic']),
        );
    }

    #[Route(path: '/reviews/negative-votes', name: ReviewLists::NEGATIVE_VOTES)]
    public function negativeVoteCleanup(): Response
    {
        return $this->listView(
            ReviewLists::NEGATIVE_VOTES,
            'Negative votes',
            ReviewLists::aggregations(without: ['Mergeable', 'Patchsets', 'Affected Files', 'Topic', 'Owner']),
        );
    }

    /**
     * The oldest work in progress first: it is the one most likely to be forgotten.
     */
    #[Route(path: '/reviews/work-in-progress', name: ReviewLists::WORK_IN_PROGRESS)]
    public function wip(): Response
    {
        return $this->listView(
            ReviewLists::WORK_IN_PROGRESS,
            'Work in progress',
            ReviewLists::aggregations(without: ['Patchsets', 'Affected Files']),
            ReviewSortEnum::LEAST_UPDATED,
        );
    }

    #[Route(path: '/reviews/ready-to-merge', name: ReviewLists::READY_TO_MERGE)]
    public function coreTeamActionRequired(): Response
    {
        return $this->listView(
            ReviewLists::READY_TO_MERGE,
            'Ready to merge',
            ReviewLists::aggregations(without: ['Mergeable', 'Patchsets', 'Affected Files']),
        );
    }

    /**
     * The changes the search words find, the tab "Reviews" of the search. Merged and abandoned ones with "searchClosed".
     */
    #[Route(path: '/search/changes', name: 'search_changes')]
    public function searchChanges(): Response
    {
        $searchTerms = $this->query()->getString('query');
        $searchClosed = '1' === $this->query()->getString('searchClosed');
        $viewData = $this->listData(
            ReviewLists::query(ReviewLists::SEARCH, $searchClosed, $searchTerms),
            ReviewLists::SEARCH,
            ReviewLists::aggregations(),
            ReviewSortEnum::UPDATED,
            '' !== trim($searchTerms),
        );
        $viewData['tabs'] = $this->globalSearch->tabs(
            $searchTerms,
            $searchClosed,
            [GlobalSearch::ISSUES => GlobalSearch::peopleQuery(
                GlobalSearch::ISSUES,
                $this->query()->all('filters'),
                $this->users,
            )],
            [GlobalSearch::CHANGES => (int) $viewData['totalResults']],
        );
        $viewData['exactMatches'] = $this->globalSearch->exactMatches($searchTerms);

        return $this->render('search/changes.html.twig', $viewData);
    }

    /**
     * Owners of the changes in the list "list" whose name contains "term", for the search in the "Owner" filter.
     * Names, numbers and avatars only, no email addresses. The lists also pass their search words "query", the search
     * also "searchClosed".
     */
    #[Route(path: '/reviews/owners', name: 'reviews_owners', methods: ['GET'])]
    public function owners(): JsonResponse
    {
        $list = $this->query()->getString('list');
        $query = $this->query()->getString('query');
        $listQuery = ReviewLists::query(
            $list,
            '1' === $this->query()->getString('searchClosed'),
            $query,
        );
        if (null === $listQuery) {
            return new JsonResponse('Unknown list', Response::HTTP_BAD_REQUEST);
        }

        $owners = $this->reviewListSearch->owners($this->query()->getString('term'), $listQuery);

        return new JsonResponse($owners);
    }

    /**
     * "Find by file" of before: the filter "File" of the search of the changes finds them now.
     */
    #[Route(path: '/gerrit/files', name: 'gerrit_files')]
    public function getFiles(): Response
    {
        $file = trim($this->query()->getString('query'));
        $parameters = '' === $file ? [] : ['filters' => ['file' => ['path' => $file]]];
        if ('1' === $this->query()->getString('searchClosed')) {
            $parameters['searchClosed'] = '1';
        }

        return $this->redirectToRoute('search_changes', $parameters, Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route(path: '/issues/stale', name: 'issues_stale')]
    public function staleIssues(): Response
    {
        $sort = IssueSortEnum::fromRequest(
            $this->query()->getString('sort'),
            false,
            StaleIssues::DEFAULT_SORT,
        );
        $page = $this->page();
        $found = $this->staleIssues->page($page->page, $page->perPage, $sort);

        $pageData = $page->withTotal($found->total)->viewData();

        return $this->render('review/stale_issues.html.twig', $pageData + [
            'issues' => $found,
            'stages' => StaleIssues::STAGES,
            'sort' => $sort,
            'sortOptions' => IssueSortEnum::options(false),
            // Links leave out the default
            'sortParameter' => StaleIssues::DEFAULT_SORT === $sort ? [] : ['sort' => $sort->value],
        ]);
    }

    /**
     * A review list: the changes of the list, narrowed down by the search words "query".
     *
     * @param array<string, mixed> $aggregations
     */
    private function listView(
        string $list,
        string $headline,
        array $aggregations,
        ReviewSortEnum $listDefault = ReviewSortEnum::UPDATED,
    ): Response {
        $searchTerms = $this->query()->getString('query');
        $viewData = $this->listData(
            ReviewLists::query($list, false, $searchTerms),
            $list,
            $aggregations,
            $listDefault,
            '' !== trim($searchTerms),
        );
        $viewData['headline'] = $headline;
        $viewData['listSearch'] = true;

        return $this->render('review/generic_review_view.html.twig', $viewData);
    }

    /**
     * The page of the list with its filters and order.
     *
     * @param array<string, mixed>|null $listQuery
     * @param array<string, mixed>      $aggregations
     * @param bool                      $withRelevance whether search words rank the changes
     *
     * @return array<string, mixed>
     *
     * @throws InvalidException
     */
    private function listData(
        ?array $listQuery,
        string $routeName,
        array $aggregations,
        ReviewSortEnum $listDefault = ReviewSortEnum::UPDATED,
        bool $withRelevance = false,
    ): array {
        $sort = ReviewSortEnum::fromRequest(
            $this->query()->getString('sort'),
            $withRelevance,
            $listDefault,
        );
        $page = $this->page();
        $filters = $this->query()->all('filters');
        $changes = $this->reviewListSearch->search($listQuery, $sort, $aggregations, $filters, $page);
        $viewData = $changes->page->viewData() + [
            'changes' => $changes->results,
            'aggregations' => $changes->facets,
        ];
        $viewData['filters'] = $filters;
        $viewData['query'] = $this->query()->getString('query');
        $viewData['routeName'] = $routeName;
        $viewData['sort'] = $sort;
        $viewData['sortOptions'] = ReviewSortEnum::options($withRelevance);
        // Links leave out the default, so changed search words keep their own default
        $isDefault = $sort === ReviewSortEnum::default($withRelevance, $listDefault);
        $viewData['sortParameter'] = $isDefault ? [] : ['sort' => $sort->value];
        // What the pages, the filters and the sort field send again
        $searchClosed = '1' === $this->query()->getString('searchClosed');
        $viewData['listParameters'] = array_filter(
            ['query' => $viewData['query'], 'searchClosed' => $searchClosed ? '1' : ''],
            static fn (string $value): bool => '' !== $value,
        );
        if ($searchClosed) {
            $viewData['searchClosed'] = '1';
        }

        return $viewData;
    }

    private function page(): Pagination
    {
        return new Pagination($this->query()->getInt('page', 1));
    }

    /**
     * @return InputBag<string>
     */
    private function query(): InputBag
    {
        return ($this->requestStack->getCurrentRequest() ?? new Request())->query;
    }
}
