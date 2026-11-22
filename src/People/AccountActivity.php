<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\People;

use App\Elastic\Bucket;
use App\Search\AccountActivityQuery;

/**
 * How much an account did, all the time: the events, the first and the last. See AccountActivityQuery.
 */
final readonly class AccountActivity
{
    public function __construct(
        public int $events,
        public ?\DateTimeImmutable $first,
        public ?\DateTimeImmutable $last,
    ) {
    }

    /**
     * @param array<string, mixed>|null $forge  the aggregation FORGE of AccountActivityQuery::request()
     * @param array<string, mixed>|null $gerrit the aggregation GERRIT
     *
     * @return array<string, self> by "forge:<number>" and "gerrit:<number>"
     */
    public static function byAccount(?array $forge, ?array $gerrit): array
    {
        $activity = [];
        foreach (['forge' => $forge, 'gerrit' => $gerrit] as $system => $aggregation) {
            foreach (Bucket::listOf($aggregation) as $bucket) {
                $activity[$system . ':' . $bucket->key] = new self(
                    $bucket->count,
                    self::date($bucket->aggregation(AccountActivityQuery::FIRST)),
                    self::date($bucket->aggregation(AccountActivityQuery::LAST)),
                );
            }
        }

        return $activity;
    }

    /**
     * @param array<string, mixed>|null $aggregation a min or max aggregation of the date
     */
    private static function date(?array $aggregation): ?\DateTimeImmutable
    {
        $milliseconds = $aggregation['value'] ?? null;

        return is_numeric($milliseconds)
            ? new \DateTimeImmutable('@' . intdiv((int) $milliseconds, 1000))
            : null;
    }
}
