<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use Elastica\Request;
use Elastica\Result;

/**
 * What a delta import needs from the index: where to start, the latest "updated_on" minus an overlap,
 * and which of the changed documents it already has.
 *
 * The index itself is the state of the import, so there is nothing else to keep in sync: after an
 * outage, a failed or a skipped run the next delta catches up on everything changed since.
 * The overlap covers changes saved at the same second and clocks that are slightly off.
 * The dates are stored in UTC, as Forge and Gerrit deliver them.
 */
final class ImportWatermark
{
    public const int OVERLAP = 600;

    public function __construct(
        private readonly Indices $indices,
    ) {
    }

    /**
     * @param 'issue'|'review' $index
     */
    public function since(string $index): ?\DateTimeImmutable
    {
        $result = $this->indices->get($index)->createSearch([
            'size' => 0,
            'aggs' => ['latest' => ['max' => ['field' => 'updated_on']]],
        ])->search();
        $latest = $result->getAggregation('latest')['value'] ?? null;

        return null === $latest ? null : self::fromLatest((int) ($latest / 1000));
    }

    /**
     * The next documents an older version of the import wrote, the ones changed last first, see ImportSchema.
     *
     * @param 'issue'|'review' $index
     *
     * @return array{ids: list<int>, total: int}
     */
    public function outdated(string $index, int $limit): array
    {
        $results = $this->indices->get($index)->search([
            'query' => ImportSchema::outdated($index),
            'sort' => [['updated_on' => 'desc']],
            '_source' => false,
            'size' => $limit,
            'track_total_hits' => true,
        ]);

        return [
            'ids' => array_map(static fn (Result $result): int => (int) $result->getId(), $results->getResults()),
            'total' => $results->getTotalHits(),
        ];
    }

    /**
     * The "updated_on" stored for the documents, in the format of the index ("Y/m/d H:i:s"), so a delta
     * skips what it already has: the overlap and repeated runs then cost no further requests or writes.
     * A document an older version of the import wrote counts as missing, it is loaded again, see ImportSchema.
     *
     * @param 'issue'|'review' $index
     * @param list<int|string> $ids
     *
     * @return array<int|string, string> keyed by id, documents not in the index or of an older import are missing
     */
    public function storedUpdates(string $index, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $response = $this->indices->get($index)->request(
            '_mget',
            Request::POST,
            ['ids' => array_map('strval', $ids)],
            ['_source' => 'updated_on,' . ImportSchema::FIELD],
        )->getData();

        $stored = [];
        foreach ($response['docs'] ?? [] as $doc) {
            if (($doc['found'] ?? false) && isset($doc['_source']['updated_on']) && ImportSchema::isCurrent($index, $doc['_source'][ImportSchema::FIELD] ?? null)) {
                $stored[(string) $doc['_id']] = (string) $doc['_source']['updated_on'];
            }
        }

        return $stored;
    }

    /**
     * The ids whose update time differs from the stored one, or that the index does not have.
     *
     * @param array<int|string, string> $updated update times by id, in the format of the index
     * @param array<int|string, string> $stored  see storedUpdates()
     *
     * @return list<string>
     */
    public static function changed(array $updated, array $stored): array
    {
        $changed = [];
        foreach ($updated as $id => $updatedOn) {
            if (($stored[(string) $id] ?? null) !== $updatedOn) {
                $changed[] = (string) $id;
            }
        }

        return $changed;
    }

    public static function fromLatest(int $timestamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . ($timestamp - self::OVERLAP));
    }

    /**
     * The value of the Redmine filter "updated_on", e.g. ">=2026-11-19T15:00:00Z".
     */
    public static function redmineFilter(\DateTimeImmutable $since): string
    {
        return '>=' . $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * The Gerrit search operator, e.g. 'after:"2026-11-19 15:00:00 +0000"'.
     */
    public static function gerritOperator(\DateTimeImmutable $since): string
    {
        return 'after:"' . $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' +0000"';
    }

    /**
     * The end of the oldest window from the start whose changes fit in a run, for a delta that has to catch up:
     * the window is halved until they fit, down to the shortest one, which is taken anyway.
     *
     * @param \Closure(\DateTimeImmutable): bool $fits lists the changes until the end, whether they fit in a run
     */
    public static function catchUpWindow(\DateTimeImmutable $since, int $window, int $shortest, \Closure $fits): \DateTimeImmutable
    {
        do {
            $until = $since->modify(sprintf('+%d seconds', $window));
            if ($fits($until)) {
                break;
            }
            $window = intdiv($window, 2);
        } while ($window >= $shortest);

        return $until;
    }

    /**
     * The Gerrit search operator for the end of a window, e.g. 'before:"2026-11-20 15:00:00 +0000"'.
     */
    public static function gerritBefore(\DateTimeImmutable $until): string
    {
        return 'before:"' . $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' +0000"';
    }
}
