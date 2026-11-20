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
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\ReadableCollection;
use Elastica\Exception\NotFoundException;
use Elastica\ResultSet;

/**
 * The Forge issues of the issue index.
 */
final readonly class IssueRepository
{
    public function __construct(
        private Indices $indices,
    ) {
    }

    public function byId(int $id): ?Issue
    {
        try {
            return Issue::fromDocument($this->indices->issues()->getDocument((string) $id)->getData());
        } catch (NotFoundException) {
            return null;
        }
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
     * @param array<string, mixed> $request
     *
     * @return SearchResult<Issue>
     */
    public function search(array $request): SearchResult
    {
        return self::fromResultSet($this->indices->issues()->createSearch($request)->search());
    }

    /**
     * @param array<string, mixed> $query
     */
    public function count(array $query): int
    {
        return $this->indices->issues()->count($query);
    }

    /**
     * @return SearchResult<Issue>
     */
    public static function fromResultSet(ResultSet $resultSet): SearchResult
    {
        return SearchResult::of($resultSet, Issue::fromDocument(...));
    }
}
