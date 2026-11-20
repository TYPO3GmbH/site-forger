<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\ReadableCollection;
use Elastica\Result;
use Elastica\ResultSet;

/**
 * The documents a search found, in the order of the response, with the total and the aggregations.
 *
 * @template-covariant T
 *
 * @implements ReadableCollection<int, T>
 */
final readonly class SearchResult implements ReadableCollection
{
    /**
     * @param ArrayCollection<int, T> $documents
     * @param array<string, mixed>    $aggregations
     */
    private function __construct(
        private ArrayCollection $documents,
        public int $total,
        public float $maxScore,
        private array $aggregations,
    ) {
    }

    /**
     * @template D
     *
     * @param \Closure(array<string, mixed>): D $document builds a document from its source
     *
     * @return self<D>
     */
    public static function of(ResultSet $resultSet, \Closure $document): self
    {
        return new self(
            new ArrayCollection(array_map(
                static fn (Result $result): mixed => $document($result->getData()),
                $resultSet->getResults(),
            )),
            $resultSet->getTotalHits(),
            $resultSet->getMaxScore(),
            $resultSet->getAggregations(),
        );
    }

    /**
     * @template D
     *
     * @param list<D>              $documents
     * @param int|null             $total        null for as many as the list has
     * @param array<string, mixed> $aggregations
     *
     * @return self<D>
     */
    public static function ofDocuments(array $documents, ?int $total = null, array $aggregations = []): self
    {
        return new self(new ArrayCollection($documents), $total ?? count($documents), 0.0, $aggregations);
    }

    /**
     * @return ReadableCollection<string, Bucket>
     */
    public function buckets(string $aggregation): ReadableCollection
    {
        return Bucket::listOf($this->aggregation($aggregation));
    }

    /**
     * @return array<string, int> how many documents each bucket has, by its key
     */
    public function counts(string $aggregation): array
    {
        return Bucket::countsOf($this->aggregation($aggregation));
    }

    /**
     * @return array<string, mixed>|null the aggregation as Elasticsearch sends it
     */
    public function aggregation(string $name): ?array
    {
        $aggregation = $this->aggregations[$name] ?? null;

        return is_array($aggregation) ? $aggregation : null;
    }

    /**
     * @return array<string, mixed> all aggregations as Elasticsearch sends them
     */
    public function aggregations(): array
    {
        return $this->aggregations;
    }

    public function contains(mixed $element): bool
    {
        return $this->documents->contains($element);
    }

    public function isEmpty(): bool
    {
        return $this->documents->isEmpty();
    }

    public function containsKey(string|int $key): bool
    {
        return $this->documents->containsKey($key);
    }

    public function get(string|int $key): mixed
    {
        return $this->documents->get($key);
    }

    public function getKeys(): array
    {
        return $this->documents->getKeys();
    }

    public function getValues(): array
    {
        return $this->documents->getValues();
    }

    public function toArray(): array
    {
        return $this->documents->toArray();
    }

    public function first(): mixed
    {
        return $this->documents->first();
    }

    public function last(): mixed
    {
        return $this->documents->last();
    }

    public function key(): ?int
    {
        return $this->documents->key();
    }

    public function current(): mixed
    {
        return $this->documents->current();
    }

    public function next(): mixed
    {
        return $this->documents->next();
    }

    public function slice(int $offset, ?int $length = null): array
    {
        return $this->documents->slice($offset, $length);
    }

    public function exists(\Closure $p): bool
    {
        return $this->documents->exists($p);
    }

    public function filter(\Closure $p): ReadableCollection
    {
        return $this->documents->filter($p);
    }

    public function map(\Closure $func): ReadableCollection
    {
        return $this->documents->map($func);
    }

    public function partition(\Closure $p): array
    {
        return $this->documents->partition($p);
    }

    public function forAll(\Closure $p): bool
    {
        return $this->documents->forAll($p);
    }

    public function indexOf(mixed $element): int|false
    {
        return $this->documents->indexOf($element);
    }

    public function findFirst(\Closure $p): mixed
    {
        return $this->documents->findFirst($p);
    }

    public function reduce(\Closure $func, mixed $initial = null): mixed
    {
        return $this->documents->reduce($func, $initial);
    }

    public function count(): int
    {
        return $this->documents->count();
    }

    public function getIterator(): \Traversable
    {
        return $this->documents->getIterator();
    }
}
