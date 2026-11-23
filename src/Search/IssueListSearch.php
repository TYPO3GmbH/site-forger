<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Document\Issue;
use App\DTO\Pagination;
use App\Elastic\Indices;
use App\Enum\ActivityEnum;
use App\Enum\IssueMarkEnum;
use App\Enum\IssueSortEnum;
use App\People\UserDirectory;
use App\Report\CategoryOverview;
use App\Report\MonthOverview;
use App\Repository\IssueRepository;
use Elastica\Aggregation\Filters;
use Elastica\Aggregation\Terms;
use Elastica\Aggregation\TopHits;
use Elastica\Query;

/**
 * The search of the issues with its filters, see SearchController.
 */
final readonly class IssueListSearch
{
    /**
     * The filters of people, see PeopleFacet. They show the people with the most issues, and the search in the
     * filter finds the others.
     */
    public const array PEOPLE_FILTERS = ['Author', 'Assigned to'];

    /**
     * The "Tags" filter shows the tags used most, and the selected ones.
     */
    private const int TAGS_SHOWN = 30;

    /**
     * The title of the filter of the marks of IssueMarkEnum, its key is "marked_as".
     */
    private const string MARKS = 'Marked as';

    /**
     * The filters that match on the field "<key>.name".
     */
    private const array NAMED_FIELDS = ['tracker', 'project', 'category', 'status', 'priority', 'fixed_version'];

    public function __construct(
        private Indices $indices,
        private UserDirectory $users,
        private PersonSuggestions $suggestions,
    ) {
    }

    /**
     * @param array<string, array<string, string>> $filters the filters of the request
     *
     * @return ListPage<Issue>
     */
    public function search(
        string $searchTerms,
        bool $includeClosed,
        IssueSortEnum $sort,
        array $filters,
        Pagination $page,
    ): ListPage {
        $request = [
            'query' => IssueSearch::query($searchTerms, $includeClosed),
            'sort' => IssueSortQuery::sort($sort),
            // Count every issue found, not only the first 10 000
            'track_total_hits' => true,
        ];
        $filterQuery = $this->filterQuery($filters);
        if (count($filterQuery) > 0) {
            $request['post_filter'] = $filterQuery;
        }
        $search = $this->indices->issues()->createSearch($request);
        $query = $search->getQuery();
        $query->setSize($page->perPage);
        $query->setFrom($page->from());
        $selection = $this->addAggregations($query, $filters);

        $issues = IssueRepository::fromResultSet($search->search());
        $facets = $issues->facets();
        foreach ([...self::PEOPLE_FILTERS, 'Tags'] as $title) {
            $facets = FacetSelection::merge($facets, $title);
        }
        // The checkboxes of the people send their keys
        foreach (self::PEOPLE_FILTERS as $title) {
            if (isset($facets[$title])) {
                $facets[$title] = PeopleFacet::keyed($facets[$title], $title, $this->users, $selection[$title]);
            }
        }
        if (isset($facets['Activity'])) {
            $facets['Activity'] = ActivityQuery::facet($facets['Activity']);
        }
        if (isset($facets[self::MARKS])) {
            $facets[self::MARKS] = IssueMarkQuery::facet($facets[self::MARKS]);
        }

        return new ListPage($issues, $facets, $page->withTotal($issues->total));
    }

    /**
     * The authors or assignees of the issues the search words find, whose name contains the term.
     *
     * @param string $filter the key of the filter, see PeopleFacet::FILTERS
     *
     * @return list<array{key: string, name: string, count: int, avatar: string|null}>|null null for an unknown filter
     */
    public function people(string $filter, string $term, string $searchTerms, bool $includeClosed): ?array
    {
        $title = array_find(
            self::PEOPLE_FILTERS,
            static fn (string $title): bool => PeopleFacet::FILTERS[$title]['key'] === $filter,
        );
        if (null === $title) {
            return null;
        }

        return $this->suggestions->find(
            $this->indices->issues(),
            $title,
            $term,
            IssueSearch::query($searchTerms, $includeClosed),
        );
    }

    /**
     * @param array<string, array<string, string>> $filters
     */
    private function filterQuery(array $filters): Query\BoolQuery
    {
        $filterQuery = new Query\BoolQuery();
        foreach ($filters as $key => $values) {
            $query = match (true) {
                'created' === $key => $this->createdQuery($values),
                null !== $this->peopleTitle((string) $key) => $this->peopleQuery((string) $key, $filters),
                default => $this->valuesQuery((string) $key, $values),
            };
            if (null !== $query) {
                $filterQuery->addMust($query);
            }
        }

        return $filterQuery;
    }

    /**
     * A range of months, not values to tick.
     *
     * @param array<string, string> $values
     */
    private function createdQuery(array $values): ?Query\Simple
    {
        $days = MonthOverview::days($values);
        $range = MonthOverview::rangeQuery($days['from'], $days['to']);

        return null === $range ? null : new Query\Simple($range);
    }

    private function peopleTitle(string $key): ?string
    {
        $title = PeopleFacet::titleOf($key);

        return in_array($title, self::PEOPLE_FILTERS, true) ? $title : null;
    }

    /**
     * @param array<string, array<string, string>> $filters
     */
    private function peopleQuery(string $key, array $filters): ?Query\Simple
    {
        $title = (string) $this->peopleTitle($key);
        $query = PeopleFacet::query($title, FacetSelection::keys($filters, $key), $this->users);

        return null === $query ? null : new Query\Simple($query);
    }

    /**
     * Any of the ticked values of a filter.
     *
     * @param array<string, string> $values
     */
    private function valuesQuery(string $key, array $values): ?Query\BoolQuery
    {
        $key = 'target_version' === $key ? 'fixed_version.name' : $key;
        $anyOf = new Query\BoolQuery();
        $count = 0;
        foreach ($values as $value => $enabled) {
            if ('true' !== $enabled) {
                continue;
            }
            $query = $this->valueQuery($key, str_replace('_', ' ', (string) $value));
            if (null !== $query) {
                $anyOf->addShould($query);
                ++$count;
            }
        }

        return $count > 0 ? $anyOf : null;
    }

    private function valueQuery(string $key, string $value): ?Query\AbstractQuery
    {
        if ('activity' === $key) {
            // Only open issues have a stage, also when closed ones are searched
            $stage = ActivityEnum::tryFromLabel($value);

            return null === $stage ? null : new Query\Simple(ActivityQuery::issues($stage));
        }
        if ('marked_as' === $key) {
            $mark = IssueMarkEnum::tryFromLabel($value);

            return null === $mark ? null : new Query\Simple(IssueMarkQuery::issues($mark));
        }
        if ('category' === $key && CategoryOverview::WITHOUT === $value) {
            return new Query\Simple(CategoryOverview::withoutCategoryQuery());
        }
        $field = in_array($key, self::NAMED_FIELDS, true) ? $key . '.name' : $key;

        return (new Query\Term())->setTerm($field, $value);
    }

    /**
     * @param array<string, array<string, string>> $filters
     *
     * @return array<string, array<int, string>> the selected people of each filter of people
     */
    private function addAggregations(Query $query, array $filters): array
    {
        $category = $this->terms('Category', 'category.name');
        // The issues without category get a value of their own, as in the row of "Issues by category"
        $category->setParam('missing', CategoryOverview::WITHOUT);
        $category->setSize(100);
        $category->setOrder('_term', 'ASC');
        $query->addAggregation($category);
        $fields = [
            'Tracker' => 'tracker.name',
            'Status' => 'status.name',
            'Priority' => 'priority.name',
            'TYPO3 Version' => 'typo3_version',
            'Target Version' => 'fixed_version.name',
            'PHP Version' => 'php_version',
        ];
        foreach ($fields as $title => $field) {
            $query->addAggregation($this->terms($title, $field));
        }
        // The index keeps the tags in lower case, see config/Elasticorn/issue/Mapping.yaml
        $tags = array_map(mb_strtolower(...), FacetSelection::selected($filters, 'tags'));
        $this->addTopTerms($query, 'Tags', 'tags', self::TAGS_SHOWN, $tags);

        // Selected people show in their filter, also when they are not among those with the most issues
        $selection = [];
        foreach (self::PEOPLE_FILTERS as $title) {
            $filter = PeopleFacet::FILTERS[$title];
            $keys = FacetSelection::keys($filters, $filter['key']);
            $selection[$title] = PeopleFacet::selection($title, $keys, $this->users);
            $numbers = array_keys($selection[$title]);
            $this->addTopTerms($query, $title, $filter['number'], FacetSelection::SIZE, $numbers, true);
        }

        $activity = new Filters('Activity');
        foreach (ActivityQuery::issueAggregation()['filters']['filters'] as $label => $filter) {
            $activity->addFilter(new Query\Simple($filter), $label);
        }
        $query->addAggregation($activity);

        $marks = new Filters(self::MARKS);
        foreach (IssueMarkQuery::aggregation()['filters']['filters'] as $label => $filter) {
            $marks->addFilter(new Query\Simple($filter), $label);
        }
        $query->addAggregation($marks);

        return $selection;
    }

    private function terms(string $title, string $field): Terms
    {
        return (new Terms($title))->setField($field);
    }

    /**
     * The values of a filter with the most issues, and the selected ones also when they are not among them.
     * A filter of people gets the latest document of each person, see PeopleFacet.
     *
     * @param list<string|int> $selected the selected values as the field has them, the numbers for people
     */
    private function addTopTerms(
        Query $query,
        string $title,
        string $field,
        int $size,
        array $selected,
        bool $people = false,
    ): void {
        $aggregations = FacetSelection::withSelected(
            [$title => FacetSelection::aggregation($field, $size)],
            $title,
            $selected,
        );
        foreach ($aggregations as $name => $definition) {
            $terms = $this->terms($name, $definition['terms']['field']);
            $terms->setSize($definition['terms']['size']);
            if (isset($definition['terms']['shard_size'])) {
                $terms->setShardSize($definition['terms']['shard_size']);
            }
            if (isset($definition['terms']['include'])) {
                $terms->setIncludeAsExactMatch($definition['terms']['include']);
            }
            if ($people) {
                $latest = PeopleFacet::latest($title)['top_hits'];
                $user = (new TopHits('user'))
                    ->setSize($latest['size'])
                    ->setSource($latest['_source'])
                    ->setSort($latest['sort']);
                $terms->addAggregation($user);
            }
            $query->addAggregation($terms);
        }
    }
}
