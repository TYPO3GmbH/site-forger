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
use App\People\UserDirectory;
use App\Search\GlobalSearch;
use App\Search\IssueListSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/search', name: 'search_')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly IssueListSearch $issueListSearch,
        private readonly GlobalSearch $globalSearch,
        private readonly UserDirectory $users,
    ) {
    }

    #[Route(name: 'search')]
    public function searchAction(Request $request): Response
    {
        $query = (string) $request->query->get('query');
        $filters = array_filter($request->query->all('filters'), is_array(...));
        $searchClosed = 1 === $request->query->getInt('searchClosed');
        // Without search words all issues score the same, so they are not sorted by relevance
        $withRelevance = '' !== trim($query);
        $sort = IssueSortEnum::fromRequest($request->query->getString('sort'), $withRelevance);
        $page = new Pagination($request->query->getInt('page', 1));

        $issues = $this->issueListSearch->search($query, $searchClosed, $sort, $filters, $page);
        $viewData = $issues->page->viewData() + [
            'issues' => $issues->results,
            'maxScore' => $issues->results->maxScore,
            'aggs' => $issues->facets,
        ];
        $viewData['sort'] = $sort;
        $viewData['sortOptions'] = IssueSortEnum::options($withRelevance);
        // Links leave out the default, so a changed search keeps its own default
        $viewData['sortParameter'] = $sort === IssueSortEnum::default($withRelevance) ? [] : ['sort' => $sort->value];
        $viewData['tabs'] = $this->globalSearch->tabs(
            $query,
            $searchClosed,
            [GlobalSearch::CHANGES => GlobalSearch::peopleQuery(
                GlobalSearch::CHANGES,
                $filters,
                $this->users,
            )],
            [GlobalSearch::ISSUES => $issues->results->total],
        );
        $viewData['exactMatches'] = $this->globalSearch->exactMatches($query);
        $viewData['query'] = $query;
        if ($searchClosed) {
            $viewData['searchClosed'] = '1';
        }
        $viewData['filters'] = $filters;
        $viewData['routeName'] = 'search_search';

        return $this->render('search/search.html.twig', $viewData);
    }

    /**
     * Authors or assignees of the issues the search words "query" find whose name contains "term", for the
     * search in their filter. Names, numbers and avatars only, no email addresses.
     */
    #[Route(path: '/people/{filter}', name: 'people', methods: ['GET'])]
    public function people(Request $request, string $filter): JsonResponse
    {
        $people = $this->issueListSearch->people(
            $filter,
            $request->query->getString('term'),
            $request->query->getString('query'),
            1 === $request->query->getInt('searchClosed'),
        );

        return null === $people
            ? new JsonResponse('Unknown filter', Response::HTTP_BAD_REQUEST)
            : new JsonResponse($people);
    }
}
