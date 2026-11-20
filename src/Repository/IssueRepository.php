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
use Doctrine\Common\Collections\ArrayCollection;
use Elastica\Exception\NotFoundException;
use Elastica\Result;
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
     * @return ArrayCollection<int, Issue> by id, the ones the index does not have left out
     */
    public function byIds(array $ids): ArrayCollection
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
     * @return ArrayCollection<int, Issue> in the order of the response
     */
    public function search(array $request): ArrayCollection
    {
        return self::fromResultSet($this->indices->issues()->createSearch($request)->search());
    }

    /**
     * @return ArrayCollection<int, Issue>
     */
    public static function fromResultSet(ResultSet $resultSet): ArrayCollection
    {
        return new ArrayCollection(array_map(
            static fn (Result $result): Issue => Issue::fromDocument($result->getData()),
            $resultSet->getResults(),
        ));
    }
}
