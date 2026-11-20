<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Document\Change;
use App\Elastic\Indices;
use Doctrine\Common\Collections\ArrayCollection;
use Elastica\Exception\NotFoundException;
use Elastica\Result;
use Elastica\ResultSet;

/**
 * The Gerrit changes of the review index.
 */
final readonly class ChangeRepository
{
    public function __construct(
        private Indices $indices,
    ) {
    }

    public function byNumber(int $number): ?Change
    {
        try {
            return Change::fromDocument($this->indices->reviews()->getDocument((string) $number)->getData());
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * @param list<int> $numbers
     *
     * @return ArrayCollection<int, Change> by number, the ones the index does not have left out
     */
    public function byNumbers(array $numbers): ArrayCollection
    {
        $numbers = array_values(array_unique($numbers));
        if ([] === $numbers) {
            return new ArrayCollection();
        }
        $changes = $this->search(['query' => ['terms' => ['id' => $numbers]], 'size' => count($numbers)]);

        return new ArrayCollection(array_combine(
            $changes->map(static fn (Change $change): int => $change->id)->toArray(),
            $changes->toArray(),
        ));
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return ArrayCollection<int, Change> in the order of the response
     */
    public function search(array $request): ArrayCollection
    {
        return self::fromResultSet($this->indices->reviews()->createSearch($request)->search());
    }

    /**
     * @return ArrayCollection<int, Change>
     */
    public static function fromResultSet(ResultSet $resultSet): ArrayCollection
    {
        return new ArrayCollection(array_map(
            static fn (Result $result): Change => Change::fromDocument($result->getData()),
            $resultSet->getResults(),
        ));
    }
}
