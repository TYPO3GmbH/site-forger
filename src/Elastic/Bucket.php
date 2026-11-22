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

/**
 * One bucket of an aggregation in a response of Elasticsearch: its key, how many documents it has, and its
 * sub-aggregations.
 */
final readonly class Bucket
{
    /**
     * @param array<string, mixed> $bucket
     */
    private function __construct(
        public string $key,
        public int $count,
        public ?string $keyAsString,
        private array $bucket,
    ) {
    }

    /**
     * @param array<string, mixed> $bucket a bucket as Elasticsearch sends it
     * @param string|null          $name   the name of the bucket of a filters aggregation, which has no key
     */
    public static function fromArray(array $bucket, ?string $name = null): self
    {
        return new self(
            (string) ($bucket['key'] ?? $name),
            (int) ($bucket['doc_count'] ?? 0),
            isset($bucket['key_as_string']) ? (string) $bucket['key_as_string'] : null,
            $bucket,
        );
    }

    /**
     * The buckets of an aggregation by their key. Terms and histograms list their buckets, filters name them.
     *
     * @param array<string, mixed>|null $aggregation
     *
     * @return ReadableCollection<array-key, Bucket>
     */
    public static function listOf(?array $aggregation): ReadableCollection
    {
        $buckets = [];
        foreach ((array) ($aggregation['buckets'] ?? []) as $name => $bucket) {
            $bucket = self::fromArray($bucket, (string) $name);
            $buckets[$bucket->key] = $bucket;
        }

        return new ArrayCollection($buckets);
    }

    /**
     * @param array<string, mixed>|null $aggregation
     *
     * @return array<array-key, int> how many documents each bucket has, by its key
     */
    public static function countsOf(?array $aggregation): array
    {
        return self::listOf($aggregation)->map(static fn (self $bucket): int => $bucket->count)->toArray();
    }

    /**
     * The buckets of an aggregation as a filter lists its values: in the order of the response.
     *
     * @param array<string, mixed>|null $aggregation
     *
     * @return ReadableCollection<int, Bucket>
     */
    public static function facetOf(?array $aggregation): ReadableCollection
    {
        return new ArrayCollection(self::listOf($aggregation)->getValues());
    }

    public function withKey(string $key): self
    {
        return new self($key, $this->count, $this->keyAsString, $this->bucket);
    }

    /**
     * @return array<string, mixed> the source of the first hit of a top_hits sub-aggregation, empty without one
     */
    public function topHit(string $name): array
    {
        $source = $this->bucket[$name]['hits']['hits'][0]['_source'] ?? [];

        return is_array($source) ? $source : [];
    }

    /**
     * @return ReadableCollection<array-key, Bucket> the buckets of a sub-aggregation
     */
    public function buckets(string $name): ReadableCollection
    {
        return self::listOf($this->aggregation($name));
    }

    /**
     * The documents of a sub-aggregation of a single bucket, like a "filter" aggregation.
     */
    public function countOf(string $name): int
    {
        return (int) ($this->bucket[$name]['doc_count'] ?? 0);
    }

    /**
     * A sub-aggregation as Elasticsearch sends it, like a histogram, null without it.
     *
     * @return array<string, mixed>|null
     */
    public function aggregation(string $name): ?array
    {
        return is_array($this->bucket[$name] ?? null) ? $this->bucket[$name] : null;
    }
}
