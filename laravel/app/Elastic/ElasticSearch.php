<?php

namespace App\Elastic;

use Elastica\Aggregation\Terms;
use Elastica\Query;

/**
 * Class ElasticSearch.
 */
class ElasticSearch
{
    /**
     * @var Connection
     */
    private $connection;

    protected $perPage = 25;

    protected $currentPage = 1;

    protected $totalHits;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array $query
     *
     * @return \Elastica\ResultSet
     *
     * @throws \Exception
     */
    public function doSearch(array $query)
    {
        if (array_key_exists('page', $_GET)) {
            $this->currentPage = (int) $_GET['page'];
        }
        $usedFilters = $this->addFilters();
        if (count($usedFilters) > 0) {
            $query['post_filter'] = $usedFilters;
        }

        $search = $this->connection->getElasticIndex()->createSearch($query);
        $search->addType('issue');
        $search->getQuery()->setSize($this->perPage);
        $search->getQuery()->setFrom(($this->currentPage * $this->perPage) - $this->perPage);

        $this->addAggregations($search->getQuery());

        $elasticaResultSet = $search->search();
        $results = $elasticaResultSet->getResults();
        $maxScore = $elasticaResultSet->getMaxScore();
        $aggs = $elasticaResultSet->getAggregations();
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
     *
     * @return array
     */
    private function addFilters()
    {
        $query = new Query();
        $filters = new Query\BoolQuery();

        if (!array_key_exists('filters', $_GET)) {
            return $filters;
        }
        foreach ($_GET['filters'] as $key => $filterValue) {
            $filterCatCount = 0;
            $filterPart = new Query\BoolQuery();
            foreach ($filterValue as $term => $enabled) {
                if ($enabled === 'true') {
                    $term = str_replace('_', ' ', $term);
                    $filter = new Query\Term();
                    if ($key === 'tracker'
                        || $key === 'project'
                        || $key === 'category'
                        || $key === 'status'
                        || $key === 'author'
                        || $key === 'priority'
                        || $key === 'fixed_version'
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
        $query->setQuery($filters);

        return $query;
    }

    /**
     * @param Query $elasticaQuery
     */
    private function addAggregations($elasticaQuery)
    {
        $catAggregation = new Terms('Category');
        $catAggregation->setField('category.name');
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

        /*$age = new \Elastica\Aggregation\Range('Age');
        $age->setParams(array(
            'script' => 'DateTime.now().year - doc[\'updated_on\'].date.year',
            'ranges' => array(
                array('from' => 0,'to' => 1),
                array('from' => 2,'to' => 3),
                array('from' => 4,'to' => 5),
            )
        ));
        $elasticaQuery->addAggregation($age);*/
    }

    /**
     * @return array
     */
    protected function getPages()
    {
        $numPages = ceil($this->totalHits / $this->perPage);
        $i = 0;
        /*
         *
         */
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
