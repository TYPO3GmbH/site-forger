<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Document\Issue;
use App\Elastic\Indices;
use App\Elastic\SearchResult;
use App\Search\IssueSearch;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\ReadableCollection;
use Elastica\Exception\NotFoundException;
use Elastica\Multi\Search as MultiSearch;
use Elastica\Result;
use Elastica\ResultSet;

/**
 * The Forge issues of the issue index.
 */
final readonly class IssueRepository
{
    private const int SCROLL_SIZE = 5000;

    /**
     * More subtasks no issue of TYPO3 Core has.
     */
    private const int SUBTASKS = 200;

    public function __construct(
        private Indices $indices,
    ) {
    }

    public function byId(int $id): ?Issue
    {
        try {
            $source = $this->indices->issues()->getDocument((string) $id)->getData();
        } catch (NotFoundException) {
            return null;
        }

        return is_array($source) ? Issue::fromDocument($source) : null;
    }

    /**
     * @param list<int> $ids
     *
     * @return ReadableCollection<int, Issue> by id, the ones the index does not have left out
     */
    public function byIds(array $ids): ReadableCollection
    {
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return new ArrayCollection();
        }
        $issues = $this->search(['query' => ['terms' => ['id' => $ids]], 'size' => count($ids)]);

        return new ArrayCollection(array_combine(
            $issues->map(static fn (Issue $issue): int => $issue->id)->toArray(),
            $issues->toArray(),
        ));
    }

    /**
     * The issues whose parent is the issue, the lowest numbers first.
     *
     * @return SearchResult<Issue>
     */
    public function subtasksOf(int $id): SearchResult
    {
        return $this->search([
            'query' => ['term' => ['parent.id' => $id]],
            'sort' => [['id' => 'asc']],
            'size' => self::SUBTASKS,
        ]);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return SearchResult<Issue>
     */
    public function search(array $request): SearchResult
    {
        return self::fromResultSet($this->indices->issues()->createSearch($request)->search());
    }

    /**
     * The issues of a search with the score of each, for a measure that compares the scores.
     *
     * @param array<string, mixed> $request
     *
     * @return list<array{Issue, float}> in the order of the response
     */
    public function scored(array $request): array
    {
        return self::scoredResults($this->indices->issues()->createSearch($request)->search());
    }

    /**
     * Several searches in one request, see scored().
     *
     * @param list<array<string, mixed>> $requests
     *
     * @return list<list<array{Issue, float}>> in the order of the requests
     */
    public function scoredMany(array $requests): array
    {
        if ([] === $requests) {
            return [];
        }
        $search = new MultiSearch($this->indices->client());
        foreach ($requests as $request) {
            $search->addSearch($this->indices->issues()->createSearch($request));
        }

        return array_values(array_map(self::scoredResults(...), $search->search()->getResultSets()));
    }

    /**
     * When each open issue was updated last, as the index has it.
     *
     * @return array<int, string> "updated_on" by the number of the issue
     */
    public function openUpdatedOn(): array
    {
        $search = $this->indices->issues()->createSearch([
            'query' => ['bool' => ['must_not' => [IssueSearch::closed()]]],
            '_source' => ['updated_on'],
            'size' => self::SCROLL_SIZE,
        ]);
        $updated = [];
        foreach ($search->scroll('1m') as $resultSet) {
            foreach ($resultSet->getResults() as $result) {
                $updated[(int) $result->getId()] = (string) ($result->getData()['updated_on'] ?? '');
            }
        }

        return $updated;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function count(array $query): int
    {
        return $this->indices->issues()->count($query);
    }

    /**
     * @return list<array{Issue, float}>
     */
    private static function scoredResults(ResultSet $resultSet): array
    {
        return array_values(array_map(
            static fn (Result $result): array => [Issue::fromDocument($result->getData()), (float) $result->getScore()],
            $resultSet->getResults(),
        ));
    }

    /**
     * @return SearchResult<Issue>
     */
    public static function fromResultSet(ResultSet $resultSet): SearchResult
    {
        return SearchResult::of($resultSet, Issue::fromDocument(...));
    }
}
