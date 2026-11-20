<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Elastic\CategoryOverview;
use App\Elastic\ElasticUtility;
use App\Elastic\FacetSelection;
use App\Elastic\GlobalSearch;
use App\Elastic\IssueSearch;
use App\Elastic\MonthOverview;
use App\Elastic\PersonSuggestion;
use App\Elastic\Users;
use App\Enum\ActivityEnum;
use App\Enum\IssueSortEnum;
use Elastica\Aggregation\Filters;
use Elastica\Aggregation\Terms;
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
     * The filters of people: title => [filter key, field of the name, field of the Forge number for the avatar].
     * They show the people with the most issues, the search in the filter finds the others.
     */
    private const array PEOPLE_FILTERS = [
        'Author' => ['author', 'author.name', 'author.id'],
        'Assigned to' => ['assigned_to', 'assigned_to.name.keyword', 'assigned_to.id'],
    ];

    /**
     * How many tags the "Tags" filter shows, the most used ones. Selected tags show, too.
     */
    private const int TAGS_SHOWN = 30;

    protected int $perPage = 25;
    protected int $currentPage = 1;
    protected int $totalHits = 0;

    /** @var array<string, array<string, string>> */
    private array $filters = [];

    /**
     * @throws \Exception
     */
    #[Route(name: 'search')]
    public function searchAction(Request $request): Response
    {
        $this->currentPage = (int) $request->query->get('page', 1);
        $query = (string) $request->query->get('query');
        $this->filters = $request->query->all('filters');

        $searchClosed = 1 === $request->query->getInt('searchClosed');
        // Without search words all issues score the same, so they are not sorted by relevance
        $withRelevance = '' !== trim($query);
        $sort = IssueSortEnum::fromRequest($request->query->getString('sort'), $withRelevance);
        $viewData = $this->findByQuery($query, $sort, $searchClosed);
        $viewData['sort'] = $sort;
        $viewData['sortOptions'] = IssueSortEnum::options($withRelevance);
        // Links leave out the default, so a changed search keeps its own default
        $viewData['sortParameter'] = $sort === IssueSortEnum::default($withRelevance) ? [] : ['sort' => $sort->value];
        $viewData['tabs'] = GlobalSearch::tabs($query, $searchClosed);
        $viewData['exactMatches'] = GlobalSearch::exactMatches($query);
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
    public function people(Request $request, string $filter, Users $users): JsonResponse
    {
        $people = array_column(self::PEOPLE_FILTERS, null, 0)[$filter] ?? null;
        if (null === $people) {
            return new JsonResponse('Unknown filter', Response::HTTP_BAD_REQUEST);
        }
        [, $nameField, $avatarField] = $people;
        $search = PersonSuggestion::request(
            $request->query->getString('term'),
            IssueSearch::query($request->query->getString('query'), 1 === $request->query->getInt('searchClosed')),
            $nameField,
            $avatarField
        );
        if (null === $search) {
            return new JsonResponse([]);
        }
        $resultSet = ElasticUtility::getIndexByName('issue')->search($search);

        return new JsonResponse(PersonSuggestion::fromAggregations($resultSet->getAggregations(), $avatarField, static fn (mixed $forgeId): ?string => $users->avatarOf((int) $forgeId, PersonSuggestion::AVATAR_SIZE)));
    }

    /**
     * @throws \Exception
     */
    private function findByQuery(string $searchTerms, IssueSortEnum $sort, bool $searchInClosedIssues = false): array
    {
        return $this->doSearch(['query' => IssueSearch::query($searchTerms, $searchInClosedIssues), 'sort' => $sort->sort()]);
    }

    /**
     * @throws \Exception
     */
    private function doSearch(array $query): array
    {
        $usedFilters = $this->addFilters();
        if (count($usedFilters) > 0) {
            $query['post_filter'] = $usedFilters;
        }

        $search = ElasticUtility::getIndexByName('issue')->createSearch($query);
        $search->getQuery()->setSize($this->perPage);
        $search->getQuery()->setFrom(($this->currentPage * $this->perPage) - $this->perPage);

        $this->addAggregations($search->getQuery());

        $elasticaResultSet = $search->search();
        $results = $elasticaResultSet->getResults();
        $maxScore = $elasticaResultSet->getMaxScore();
        $aggs = $elasticaResultSet->getAggregations();
        foreach ([...array_keys(self::PEOPLE_FILTERS), 'Tags'] as $title) {
            $aggs = FacetSelection::merge($aggs, $title);
        }
        if (isset($aggs['Activity'])) {
            $aggs['Activity'] = ActivityEnum::buckets($aggs['Activity']);
        }
        $this->totalHits = $elasticaResultSet->getTotalHits();

        $out = [
            'pagesToLinkTo' => $this->getPages(),
            'currentPage' => $this->currentPage,
            'prev' => $this->currentPage - 1,
            'next' => $this->currentPage < ceil($this->totalHits / $this->perPage) ? $this->currentPage + 1 : 0,
            'totalResults' => $this->totalHits,
            'startingAtItem' => ($this->currentPage * $this->perPage) - ($this->perPage - 1),
            'endingAtItem' => $this->currentPage * $this->perPage,
            'results' => $results,
            'maxScore' => $maxScore,
            'aggs' => $aggs,
        ];
        if ((int) $this->totalHits <= (int) $out['endingAtItem']) {
            $out['endingAtItem'] = (int) $this->totalHits;
        }

        return $out;
    }

    /**
     * Adds filters based on get parameters.
     */
    private function addFilters(): Query|Query\BoolQuery
    {
        $filters = new Query\BoolQuery();

        foreach ($this->filters as $key => $filterValue) {
            if ('created' === $key) {
                // A range of months, not values to tick
                $days = is_array($filterValue) ? MonthOverview::days($filterValue) : ['from' => null, 'to' => null];
                $range = MonthOverview::rangeQuery($days['from'], $days['to']);
                if (null !== $range) {
                    $filters->addMust(new Query\Simple($range));
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
                        || 'author' === $key
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
            'assigned_to' => 'assigned_to.name.keyword',
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

        $this->addTopTerms($elasticaQuery, 'Tags', 'tags', 'tags', self::TAGS_SHOWN);

        // Selected people show in their filter also when they are not among those with the most issues
        foreach (self::PEOPLE_FILTERS as $title => [$key, $field]) {
            $this->addTopTerms($elasticaQuery, $title, $key, $field, FacetSelection::SIZE);
        }

        $activity = new Filters('Activity');
        foreach (ActivityEnum::issueAggregation()['filters']['filters'] as $label => $filter) {
            $activity->addFilter(new Query\Simple($filter), $label);
        }
        $elasticaQuery->addAggregation($activity);

    }

    /**
     * The values of a filter with the most issues, and the selected ones also when they are not among them.
     */
    private function addTopTerms(Query $elasticaQuery, string $title, string $key, string $field, int $size): void
    {
        $aggregations = FacetSelection::withSelected([$title => FacetSelection::aggregation($field, $size)], $title, FacetSelection::selected($this->filters, $key));
        foreach ($aggregations as $name => $definition) {
            $terms = new Terms($name);
            $terms->setField($definition['terms']['field']);
            $terms->setSize($definition['terms']['size']);
            if (isset($definition['terms']['include'])) {
                $terms->setIncludeAsExactMatch($definition['terms']['include']);
            }
            $elasticaQuery->addAggregation($terms);
        }
    }

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
