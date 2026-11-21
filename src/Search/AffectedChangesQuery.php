<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\ReviewStatusEnum;

/**
 * The open changes a delta of Gerrit has to load again, because changes it imported affect them without updating
 * them in Gerrit, see GerritImport::importAffected():
 *
 * - a change merged into a branch can make the open changes on it that touch its files unmergeable,
 * - a new patch set changes the relation chain of the changes above it,
 * - a change that changes takes or leaves the merge conflicts of other changes.
 */
final class AffectedChangesQuery
{
    /**
     * @param list<array<string, mixed>> $documents the documents the delta wrote, see GerritChangeImporter::documents()
     * @param int                        $limit     the most changes to load again
     *
     * @return array<string, mixed>|null null when no change can be affected
     */
    public static function request(array $documents, int $limit): ?array
    {
        $imported = array_map(static fn (array $document): int => (int) $document['id'], $documents);
        $should = [];
        foreach ($documents as $document) {
            $merged = ReviewStatusEnum::MERGED->value === ($document['reviewstatus'] ?? null);
            if ($merged && [] !== ($document['files'] ?? [])) {
                $should[] = ['bool' => ['filter' => [
                    ['term' => ['branch' => $document['branch'] ?? '']],
                    ['terms' => ['files' => array_values((array) $document['files'])]],
                ]]];
            }
        }
        $related = array_merge(...array_map(
            static fn (array $document): array => [
                ...array_column((array) ($document['chain'] ?? []), 'number'),
                ...(array) ($document['merge_conflicts'] ?? []),
            ],
            $documents,
        ));
        if ([] !== $related) {
            $should[] = ['terms' => ['id' => array_values(array_unique(array_map(intval(...), $related)))]];
        }
        if ([] !== $imported) {
            // The changes that named an imported change as a conflict before
            $should[] = ['terms' => ['merge_conflicts' => $imported]];
        }
        if ([] === $should) {
            return null;
        }

        return [
            'query' => ['bool' => [
                'should' => $should,
                'minimum_should_match' => 1,
                'filter' => [['term' => ['reviewstatus' => ReviewStatusEnum::NEW->value]]],
                'must_not' => [['terms' => ['id' => $imported]]],
            ]],
            '_source' => ['id'],
            'sort' => [['updated_on' => 'desc']],
            'size' => $limit,
        ];
    }
}
