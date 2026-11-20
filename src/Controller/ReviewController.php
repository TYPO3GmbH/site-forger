<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Elastic\FacetSelection;
use App\Elastic\GlobalSearch;
use App\Elastic\Indices;
use App\Elastic\MonthOverview;
use App\Elastic\PeopleFacet;
use App\Elastic\PersonSuggestion;
use App\Elastic\ReviewLists;
use App\Elastic\StaleIssues;
use App\Elastic\Users;
use App\Enum\ActivityEnum;
use App\Enum\IssueSortEnum;
use App\Enum\ReviewSizeEnum;
use App\Enum\ReviewSortEnum;
use Elastica\Exception\InvalidException;
use Elastica\Query\BoolQuery;
use Elastica\Query\Range;
use Elastica\Query\Simple;
use Elastica\Query\Term;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReviewController extends AbstractController
{
    private int $perPage = 25;
    private int $currentPage = 1;
    private ?int $totalHits = null;

    public function __construct(
        private readonly Indices $indices,
        private readonly GlobalSearch $globalSearch,
        private readonly RequestStack $requestStack,
        private readonly Users $users,
    ) {
        $this->currentPage = $this->query()->getInt('page', 1);
    }

    #[Route(path: '/gerrit/status', name: 'gerrit_index')]
    public function status(): Response
    {
        return $this->listView(ReviewLists::READY, 'Ready for review', $this->queryAggregations());
    }

    #[Route(path: '/gerrit/merge-ready', name: 'gerrit_hasverifications')]
    public function hasVerifications(): Response
    {
        return $this->listView(ReviewLists::REVIEWED_AND_VERIFIED, 'Reviewed and verified', $this->queryAggregations());
    }

    #[Route(path: '/gerrit/misses-verification', name: 'gerrit_missesverifications')]
    public function missesVerifications(): Response
    {
        return $this->listView(ReviewLists::NEEDS_VERIFICATION, 'Needs verification', $this->queryAggregations());
    }

    #[Route(path: '/gerrit/misses-review', name: 'gerrit_missesreview')]
    public function missesReview(): Response
    {
        return $this->listView(ReviewLists::NEEDS_REVIEW, 'Needs review', $this->queryAggregations());
    }

    #[Route(path: '/gerrit/rebase', name: 'gerrit_rebase')]
    public function rebase(): Response
    {
        return $this->listView(ReviewLists::NEEDS_REBASE, 'Needs rebase', $this->queryAggregations(without: ['Mergeable', 'Patchsets', 'Releases', 'Topic']));
    }

    #[Route(path: '/gerrit/negative-vote-cleanup', name: 'gerrit_negative_vote_cleanup')]
    public function negativeVoteCleanup(): Response
    {
        return $this->listView(ReviewLists::NEGATIVE_VOTES, 'Negative votes', $this->queryAggregations(without: ['Mergeable', 'Patchsets', 'Affected Files', 'Topic', 'Owner']));
    }

    /**
     * The oldest work in progress first: it is the one most likely to be forgotten.
     */
    #[Route(path: '/gerrit/wip', name: 'gerrit_wip')]
    public function wip(): Response
    {
        return $this->listView(ReviewLists::WORK_IN_PROGRESS, 'Work in progress', $this->queryAggregations(without: ['Patchsets', 'Affected Files']), ReviewSortEnum::LEAST_UPDATED);
    }

    #[Route(path: '/gerrit/core-team-merge', name: 'gerrit_coreteam_merge')]
    public function coreTeamActionRequired(): Response
    {
        return $this->listView(ReviewLists::READY_TO_MERGE, 'Ready to merge', $this->queryAggregations(without: ['Mergeable', 'Patchsets', 'Affected Files']));
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
            $this->queryAggregations(),
            ReviewSortEnum::UPDATED,
            '' !== trim($searchTerms),
        );
        $viewData['tabs'] = $this->globalSearch->tabs(
            $searchTerms,
            $searchClosed,
            [GlobalSearch::ISSUES => GlobalSearch::peopleQuery(GlobalSearch::ISSUES, $this->query()->all('filters'), $this->users)],
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
    #[Route(path: '/gerrit/owners', name: 'gerrit_owners', methods: ['GET'])]
    public function owners(): JsonResponse
    {
        $list = $this->query()->getString('list');
        $query = $this->query()->getString('query');
        $listQuery = ReviewLists::query($list, '1' === $this->query()->getString('searchClosed'), $query);
        if (null === $listQuery) {
            return new JsonResponse('Unknown list', Response::HTTP_BAD_REQUEST);
        }
        $request = PersonSuggestion::request($this->query()->getString('term'), $listQuery, 'Owner');
        if (null === $request) {
            return new JsonResponse([]);
        }
        $resultSet = $this->indices->get('review')->search($request);

        return new JsonResponse(PersonSuggestion::fromAggregations($resultSet->getAggregations(), 'Owner', fn (array $user): ?string => $this->users->avatarOfUser($user, PersonSuggestion::AVATAR_SIZE), $this->users->keyOf(...)));
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

    #[Route(path: '/gerrit/stale-issues', name: 'gerrit_stale_issues')]
    public function staleIssues(): Response
    {
        $sort = IssueSortEnum::fromRequest($this->query()->getString('sort'), false, StaleIssues::DEFAULT_SORT);
        $resultSet = $this->indices->get('issue')->createSearch(StaleIssues::issuesRequest($this->currentPage, $this->perPage, $sort))->search();
        $issues = array_map(static fn ($result): array => $result->getData(), $resultSet->getResults());
        $changesRequest = StaleIssues::changesRequest($issues);
        $changes = null === $changesRequest ? [] : array_map(
            static fn ($result): array => $result->getData(),
            $this->indices->get('review')->createSearch($changesRequest)->search()->getResults(),
        );
        $this->totalHits = $resultSet->getTotalHits();
        $from = ($this->currentPage - 1) * $this->perPage;

        return $this->render('review/stale_issues.html.twig', [
            'issues' => StaleIssues::withChanges($issues, $changes),
            'stages' => StaleIssues::STAGES,
            'totalResults' => $this->totalHits,
            'startingAtItem' => $from + 1,
            'endingAtItem' => $from + count($issues),
            'pagesToLinkTo' => $this->getPages(),
            'currentPage' => $this->currentPage,
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
    private function listView(string $list, string $headline, array $aggregations, ReviewSortEnum $listDefault = ReviewSortEnum::UPDATED): Response
    {
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
    private function listData(?array $listQuery, string $routeName, array $aggregations, ReviewSortEnum $listDefault = ReviewSortEnum::UPDATED, bool $withRelevance = false): array
    {
        $sort = ReviewSortEnum::fromRequest($this->query()->getString('sort'), $withRelevance, $listDefault);
        $fullRequest = [
            'query' => $listQuery,
            'sort' => $sort->sort(),
            'size' => $this->perPage,
            'from' => $this->currentPage * $this->perPage - $this->perPage,
            'aggregations' => $aggregations,
        ];
        $viewData = $this->search($fullRequest);
        $viewData['routeName'] = $routeName;
        $viewData['sort'] = $sort;
        $viewData['sortOptions'] = ReviewSortEnum::options($withRelevance);
        // Links leave out the default, so changed search words keep their own default
        $viewData['sortParameter'] = $sort === ReviewSortEnum::default($withRelevance, $listDefault) ? [] : ['sort' => $sort->value];
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

    /**
     * @throws InvalidException
     */
    protected function search(array $fullRequest): array
    {
        $viewData = [];
        $usedFilters = $this->addFilters();

        /**
         * The reason for this double check is:
         * $usedFilters before converted to an array, count can be 0, and if so the ->toArray() function
         * used in the second check is throwing an exception. With only the first check, the filtering with
         * ->toArray() isn't working correct.
         */
        if ($usedFilters->count() > 0 && count($usedFilters->toArray()) > 0) {
            $fullRequest['post_filter'] = $usedFilters;
        }
        // Selected owners show in the filter also when they are not among those with the most changes: by number
        $owners = PeopleFacet::selection('Owner', FacetSelection::keys($this->query()->all('filters'), 'owner'), $this->users);
        $fullRequest['aggregations'] = FacetSelection::withSelected($fullRequest['aggregations'] ?? [], 'Owner', array_keys($owners));
        $query = $this->indices->get('review')->createSearch($fullRequest);
        $resultSet = $query->search();
        $this->totalHits = $resultSet->getTotalHits();
        if ((int) $this->totalHits <= ($this->currentPage * $this->perPage)) {
            $viewData['endingAtItem'] = (int) $this->totalHits;
        } else {
            $viewData['endingAtItem'] = $this->currentPage * $this->perPage;
        }
        $viewData['results'] = $resultSet->getResults();
        $viewData['pagesToLinkTo'] = $this->getPages();
        $viewData['currentPage'] = $this->currentPage;
        $viewData['prev'] = $this->currentPage - 1;
        $viewData['next'] = $this->currentPage < ceil($this->totalHits / $this->perPage) ? $this->currentPage + 1 : 0;
        $viewData['totalResults'] = $this->totalHits;
        $viewData['startingAtItem'] = ($this->currentPage * $this->perPage) - ($this->perPage - 1);
        // Elasticsearch answers in an order of its own, the filters stand in the order they were asked for
        $viewData['aggregations'] = self::inOrderOf(
            FacetSelection::merge($resultSet->getAggregations(), 'Owner'),
            array_keys($fullRequest['aggregations'])
        );
        // The owners by their keys, as their checkboxes send them
        if (isset($viewData['aggregations']['Owner'])) {
            $viewData['aggregations']['Owner'] = PeopleFacet::keyed($viewData['aggregations']['Owner'], 'Owner', $this->users, $owners);
        }
        if (isset($viewData['aggregations']['Activity'])) {
            $viewData['aggregations']['Activity'] = ActivityEnum::buckets($viewData['aggregations']['Activity']);
        }
        if (isset($viewData['aggregations']['Size'])) {
            $viewData['aggregations']['Size'] = ReviewSizeEnum::buckets($viewData['aggregations']['Size']);
        }
        $viewData['filters'] = $this->query()->all('filters');
        $viewData['query'] = $this->query()->getString('query');

        return $viewData;
    }

    /**
     * The aggregations in the given order of their titles, those not in it after them.
     *
     * @param array<string, mixed> $aggregations
     * @param list<string>         $titles
     *
     * @return array<string, mixed>
     */
    public static function inOrderOf(array $aggregations, array $titles): array
    {
        return array_replace(array_intersect_key(array_flip($titles), $aggregations), $aggregations);
    }

    private function query(): InputBag
    {
        return $this->requestStack->getCurrentRequest()->query ?? new InputBag();
    }

    /**
     * Makes the date string from elastic JS compatible.
     */
    protected function fixTime($in): string
    {
        return str_replace('/', '-', substr((string) $in, 0, 10));
    }

    protected function addFilters(): BoolQuery
    {
        $filters = new BoolQuery();

        foreach ($this->query()->all('filters') as $key => $filterValue) {
            if ('file' === $key) {
                // A path typed in, not values to tick
                $file = ReviewLists::fileQuery(is_array($filterValue) ? (string) ($filterValue['path'] ?? '') : '');
                if (null !== $file) {
                    $filters->addMust(new Simple($file));
                }
                continue;
            }
            if ('updated' === $key) {
                // The days of the last update, from the datepicker, not values to tick
                $days = is_array($filterValue) ? MonthOverview::days($filterValue) : ['from' => null, 'to' => null];
                $range = MonthOverview::rangeQuery($days['from'], $days['to'], 'updated_on');
                if (null !== $range) {
                    $filters->addMust(new Simple($range));
                }
                continue;
            }
            if ('owner' === $key) {
                // A person by the key, see PeopleFacet
                $owner = PeopleFacet::query('Owner', FacetSelection::keys($this->query()->all('filters'), 'owner'), $this->users);
                if (null !== $owner) {
                    $filters->addMust(new Simple($owner));
                }
                continue;
            }
            $filterPart = new BoolQuery();
            $filterCatCount = 0;
            foreach ($filterValue as $term => $enabled) {
                if ('true' === $enabled) {
                    $term = str_replace('_', ' ', (string) $term);
                    if ('activity' === $key) {
                        $stage = ActivityEnum::tryFromLabel($term);
                        if (null === $stage) {
                            continue;
                        }
                        // Only open changes have a stage, also when closed ones are searched
                        $filter = new Simple($stage->changeQuery());
                    } elseif ('size' === $key) {
                        $size = ReviewSizeEnum::tryFromLabel($term);
                        if (null === $size) {
                            continue;
                        }
                        $filter = new Simple($size->changeQuery());
                    } elseif ('updated_on' === $key) {
                        // A month of the filter "Updated on" ticked before the datepicker, in links shared then
                        $filter = new Range();
                        $startDate = new \DateTime('@' . intdiv((int) $term, 1000));
                        $endDate = new \DateTime('@' . intdiv((int) $term, 1000))->modify('+1 month -1 second');
                        $filter->addField(
                            $key,
                            [
                                'gte' => $startDate->format('Y/m/d H:i:s'),
                                'lte' => $endDate->format('Y/m/d H:i:s'),
                            ]
                        );
                    } else {
                        $filter = new Term();
                        if ('tracker' === $key
                            || 'project' === $key
                            || 'category' === $key
                            || 'status' === $key
                            || 'author' === $key
                            || 'priority' === $key
                            || 'fixed_version' === $key
                        ) {
                            $filter->setTerm($key . '.name', $term);
                        } elseif ('type' === $key) {
                            $filter->setTerm('reviewtags', $term);
                        } else {
                            $filter->setTerm($key, $term);
                        }
                    }

                    $filterPart->addShould($filter);
                    ++$filterCatCount;
                }
            }
            if ($filterCatCount > 0) {
                $filters->addMust($filterPart);
            }
        }

        return $filters;
    }

    /**
     * The filters of the lists.
     *
     * @param list<string> $without the filters a list leaves out, because all of its changes have the same value
     *
     * @return array<string, mixed>
     */
    protected function queryAggregations(array $without = []): array
    {
        return array_diff_key($this->allAggregations(), array_flip($without));
    }

    /**
     * @return array<string, mixed>
     */
    private function allAggregations(): array
    {
        return [
            // First: how long the review takes is what reviewers pick by.
            // Added and removed lines together, see ReviewSizeEnum, filtered as "filters[size][...]"
            'Size' => ReviewSizeEnum::changeAggregation(),
            'Releases' => [
                'terms' => [
                    'field' => 'releases',
                ],
            ],
            'Affected Files' => [
                'terms' => [
                    'field' => 'affected_files',
                ],
            ],
            'Topic' => [
                'terms' => [
                    'field' => 'topic',
                    'order' => [
                        '_term' => 'asc',
                    ],
                ],
            ],
            'Patchsets' => [
                'terms' => [
                    'field' => 'patchsets',
                ],
            ],
            'Mergeable' => [
                'terms' => [
                    'field' => 'mergeable',
                ],
            ],
            // The owners with the most changes only, the others are found with the search in the filter
            'Owner' => PeopleFacet::aggregation('Owner'),
            // The tags in the subject, like "BUGFIX" or "!!!", filtered as "filters[type][...]"
            'Type' => [
                'terms' => [
                    'field' => 'reviewtags',
                    'size' => 50,
                ],
            ],
            'Activity' => ActivityEnum::changeAggregation(),
        ];
    }

    /**
     * Returns an array of linkable pages.
     */
    protected function getPages(): array
    {
        $numPages = ceil($this->totalHits / $this->perPage);
        $i = 0;

        $maxPages = $numPages;
        if ($numPages > 15 && $this->currentPage <= 7) {
            $numPages = 15;
        }
        if ($this->currentPage > 7) {
            $i = $this->currentPage - 7;
            $numPages = $this->currentPage + 6;
        }
        if ($numPages > $maxPages) {
            $numPages = $maxPages;
            $i = $maxPages - 15;
        }

        if ($i < 0) {
            $i = 0;
        }

        $out = [];
        while ($i < $numPages) {
            $out[(int) $i] = ($i + 1);
            ++$i;
        }

        return $out;
    }
}
