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
     * @return ArrayCollection<string, Bucket>
     */
    public static function listOf(?array $aggregation): ArrayCollection
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
     * @return array<string, int> how many documents each bucket has, by its key
     */
    public static function countsOf(?array $aggregation): array
    {
        return self::listOf($aggregation)->map(static fn (self $bucket): int => $bucket->count)->toArray();
    }

    /**
     * @return ArrayCollection<string, Bucket> the buckets of a sub-aggregation
     */
    public function buckets(string $name): ArrayCollection
    {
        return self::listOf(is_array($this->bucket[$name] ?? null) ? $this->bucket[$name] : null);
    }
}
