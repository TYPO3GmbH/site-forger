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
use App\Elastic\CategoryOverview;
use App\Elastic\FacetSelection;
use App\Elastic\GlobalSearch;
use App\Elastic\Indices;
use App\Elastic\IssueSearch;
use App\Elastic\MonthOverview;
use App\Elastic\PeopleFacet;
use App\Elastic\PersonSuggestion;
use App\Elastic\Users;
use App\Enum\ActivityEnum;
use App\Enum\IssueSortEnum;
use Elastica\Aggregation\Filters;
use Elastica\Aggregation\Terms;
use Elastica\Aggregation\TopHits;
use Elastica\Query;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/search', name: 'search_')]
class SearchController extends AbstractController
{
    /**
     * The filters of people, see PeopleFacet: they show the people with the most issues, the search in the filter
     * finds the others.
     */
    private const array PEOPLE_FILTERS = ['Author', 'Assigned to'];

    /**
     * How many tags the "Tags" filter shows, the most used ones. Selected tags show, too.
     */
    private const int TAGS_SHOWN = 30;

    private Pagination $page;

    /** @var array<string, array<string, string>> */
    private array $filters = [];

    /**
     * The selected people of each filter of people, see PeopleFacet::selection().
     *
     * @var array<string, array<int, string>>
     */
    private array $selection = [];

    public function __construct(
        private readonly Indices $indices,
        private readonly GlobalSearch $globalSearch,
        private readonly Users $users,
    ) {
    }

    /**
     * @throws \Exception
     */
    #[Route(name: 'search')]
    public function searchAction(Request $request): Response
    {
        $this->page = new Pagination($request->query->getInt('page', 1));
        $query = (string) $request->query->get('query');
        $this->filters = array_filter($request->query->all('filters'), is_array(...));

        $searchClosed = 1 === $request->query->getInt('searchClosed');
        // Without search words all issues score the same, so they are not sorted by relevance
        $withRelevance = '' !== trim($query);
        $sort = IssueSortEnum::fromRequest($request->query->getString('sort'), $withRelevance);
        $viewData = $this->findByQuery($query, $sort, $searchClosed);
        $viewData['sort'] = $sort;
        $viewData['sortOptions'] = IssueSortEnum::options($withRelevance);
        // Links leave out the default, so a changed search keeps its own default
        $viewData['sortParameter'] = $sort === IssueSortEnum::default($withRelevance) ? [] : ['sort' => $sort->value];
        $viewData['tabs'] = $this->globalSearch->tabs(
            $query,
            $searchClosed,
            [GlobalSearch::CHANGES => GlobalSearch::peopleQuery(GlobalSearch::CHANGES, $this->filters, $this->users)],
            [GlobalSearch::ISSUES => (int) $viewData['totalResults']],
        );
        $viewData['exactMatches'] = $this->globalSearch->exactMatches($query);
        $viewData['query'] = $query;
        if ($searchClosed) {
            $viewData['searchClosed'] = '1';
        }
        $viewData['filters'] = $this->filters;
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
        $title = array_find(self::PEOPLE_FILTERS, static fn (string $title): bool => PeopleFacet::FILTERS[$title]['key'] === $filter);
        if (null === $title) {
            return new JsonResponse('Unknown filter', Response::HTTP_BAD_REQUEST);
        }
        $search = PersonSuggestion::request(
            $request->query->getString('term'),
            IssueSearch::query($request->query->getString('query'), 1 === $request->query->getInt('searchClosed')),
            $title,
        );
        if (null === $search) {
            return new JsonResponse([]);
        }
        $resultSet = $this->indices->issues()->search($search);

        return new JsonResponse(PersonSuggestion::fromAggregations($resultSet->getAggregations(), $title, fn (array $user): ?string => $this->users->avatarOfUser($user, PersonSuggestion::AVATAR_SIZE), $this->users->keyOf(...)));
    }

    /**
     * @return array<string, mixed> the data for the result list
     *
     * @throws \Exception
     */
    private function findByQuery(string $searchTerms, IssueSortEnum $sort, bool $searchInClosedIssues = false): array
    {
        return $this->doSearch(['query' => IssueSearch::query($searchTerms, $searchInClosedIssues), 'sort' => $sort->sort()]);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed> the data for the result list
     *
     * @throws \Exception
     */
    private function doSearch(array $query): array
    {
        $usedFilters = $this->addFilters();
        if (count($usedFilters) > 0) {
            $query['post_filter'] = $usedFilters;
        }

        $search = $this->indices->issues()->createSearch($query);
        $search->getQuery()->setSize($this->page->perPage);
        $search->getQuery()->setFrom($this->page->from());

        $this->addAggregations($search->getQuery());

        $elasticaResultSet = $search->search();
        $results = $elasticaResultSet->getResults();
        $maxScore = $elasticaResultSet->getMaxScore();
        $aggs = $elasticaResultSet->getAggregations();
        foreach ([...self::PEOPLE_FILTERS, 'Tags'] as $title) {
            $aggs = FacetSelection::merge($aggs, $title);
        }
        // The people by their keys, as their checkboxes send them
        foreach (self::PEOPLE_FILTERS as $title) {
            if (isset($aggs[$title])) {
                $aggs[$title] = PeopleFacet::keyed($aggs[$title], $title, $this->users, $this->selection[$title] ?? []);
            }
        }
        if (isset($aggs['Activity'])) {
            $aggs['Activity'] = ActivityEnum::buckets($aggs['Activity']);
        }

        return $this->page->withTotal($elasticaResultSet->getTotalHits())->viewData() + [
            'results' => $results,
            'maxScore' => $maxScore,
            'aggs' => $aggs,
        ];
    }

    /**
     * Adds filters based on get parameters.
     */
    private function addFilters(): Query\BoolQuery
    {
        $filters = new Query\BoolQuery();

        foreach ($this->filters as $key => $filterValue) {
            if ('created' === $key) {
                // A range of months, not values to tick
                $days = MonthOverview::days($filterValue);
                $range = MonthOverview::rangeQuery($days['from'], $days['to']);
                if (null !== $range) {
                    $filters->addMust(new Query\Simple($range));
                }
                continue;
            }
            // A person by the key, see PeopleFacet
            $people = PeopleFacet::titleOf((string) $key);
            if (null !== $people && in_array($people, self::PEOPLE_FILTERS, true)) {
                $query = PeopleFacet::query($people, FacetSelection::keys($this->filters, (string) $key), $this->users);
                if (null !== $query) {
                    $filters->addMust(new Query\Simple($query));
                }
                continue;
            }
            $key = $this->mapFilterNames($key);
            $filterCatCount = 0;
            $filterPart = new Query\BoolQuery();
            foreach ($filterValue as $term => $enabled) {
                if ('true' === $enabled) {
                    $term = str_replace('_', ' ', (string) $term);
                    if ('activity' === $key) {
                        $stage = ActivityEnum::tryFromLabel($term);
                        if (null === $stage) {
                            continue;
                        }
                        // Only open issues have a stage, also when closed ones are searched
                        $filterPart->addShould(new Query\Simple($stage->issueQuery()));
                        ++$filterCatCount;
                        continue;
                    }
                    if ('category' === $key && CategoryOverview::WITHOUT === $term) {
                        $filterPart->addShould(new Query\Simple(CategoryOverview::withoutCategoryQuery()));
                        ++$filterCatCount;
                        continue;
                    }
                    $filter = new Query\Term();
                    if ('tracker' === $key
                        || 'project' === $key
                        || 'category' === $key
                        || 'status' === $key
                        || 'priority' === $key
                        || 'fixed_version' === $key
                    ) {
                        $filter->setTerm($key . '.name', $term);
                    } else {
                        $filter->setTerm($key, $term);
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

    private function mapFilterNames(string $key): string
    {
        return match ($key) {
            'target_version' => 'fixed_version.name',
            default => $key,
        };
    }

    private function addAggregations(Query $elasticaQuery): void
    {
        $catAggregation = new Terms('Category');
        $catAggregation->setField('category.name');
        // The issues without category as a value of their own, as the row of "Issues by category"
        $catAggregation->setParam('missing', CategoryOverview::WITHOUT);
        $catAggregation->setSize(100);
        $catAggregation->setOrder('_term', 'ASC');
        $elasticaQuery->addAggregation($catAggregation);

        $trackerAggregation = new Terms('Tracker');
        $trackerAggregation->setField('tracker.name');
        $elasticaQuery->addAggregation($trackerAggregation);

        $status = new Terms('Status');
        $status->setField('status.name');
        $elasticaQuery->addAggregation($status);

        $priority = new Terms('Priority');
        $priority->setField('priority.name');
        $elasticaQuery->addAggregation($priority);

        $t3ver = new Terms('TYPO3 Version');
        $t3ver->setField('typo3_version');
        $elasticaQuery->addAggregation($t3ver);

        $targetver = new Terms('Target Version');
        $targetver->setField('fixed_version.name');
        $elasticaQuery->addAggregation($targetver);

        $phpVer = new Terms('PHP Version');
        $phpVer->setField('php_version');
        $elasticaQuery->addAggregation($phpVer);

        $this->addTopTerms($elasticaQuery, 'Tags', 'tags', self::TAGS_SHOWN, FacetSelection::selected($this->filters, 'tags'));

        // Selected people show in their filter also when they are not among those with the most issues: by number
        foreach (self::PEOPLE_FILTERS as $title) {
            $this->selection[$title] = PeopleFacet::selection($title, FacetSelection::keys($this->filters, PeopleFacet::FILTERS[$title]['key']), $this->users);
            $this->addTopTerms($elasticaQuery, $title, PeopleFacet::FILTERS[$title]['number'], FacetSelection::SIZE, array_keys($this->selection[$title]), true);
        }

        $activity = new Filters('Activity');
        foreach (ActivityEnum::issueAggregation()['filters']['filters'] as $label => $filter) {
            $activity->addFilter(new Query\Simple($filter), $label);
        }
        $elasticaQuery->addAggregation($activity);

    }

    /**
     * The values of a filter with the most issues, and the selected ones also when they are not among them; of a
     * filter of people each with its latest document, see PeopleFacet.
     *
     * @param list<string|int> $selected the selected values, as the field has them: the numbers of the people
     */
    private function addTopTerms(Query $elasticaQuery, string $title, string $field, int $size, array $selected, bool $people = false): void
    {
        $aggregations = FacetSelection::withSelected([$title => FacetSelection::aggregation($field, $size)], $title, $selected);
        foreach ($aggregations as $name => $definition) {
            $terms = new Terms($name);
            $terms->setField($definition['terms']['field']);
            $terms->setSize($definition['terms']['size']);
            if (isset($definition['terms']['shard_size'])) {
                $terms->setShardSize($definition['terms']['shard_size']);
            }
            if (isset($definition['terms']['include'])) {
                $terms->setIncludeAsExactMatch($definition['terms']['include']);
            }
            if ($people) {
                $latest = PeopleFacet::latest($title)['top_hits'];
                $terms->addAggregation((new TopHits('user'))->setSize($latest['size'])->setSource($latest['_source'])->setSort($latest['sort']));
            }
            $elasticaQuery->addAggregation($terms);
        }
    }
}
