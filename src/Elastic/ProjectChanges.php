<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use Elastica\Query;

/**
 * The Gerrit changes in the index. Forger shows only what Gerrit shows to everyone: a change that Gerrit does
 * not show any more (private now, deleted, in another project) is removed, see GerritChangeImporter::refresh()
 * and the full import.
 *
 * Not final: the tests stub it, they have no Elasticsearch.
 */
readonly class ProjectChanges
{
    /**
     * The numbers of all changes in the index.
     *
     * @return \Generator<int>
     */
    public function indexedNumbers(): \Generator
    {
        $search = ElasticUtility::getIndexByName('review')->createSearch(new Query([
            '_source' => false,
            'size' => 1000,
            'query' => ['match_all' => new \stdClass()],
        ]));
        foreach ($search->scroll('2m') as $resultSet) {
            foreach ($resultSet as $result) {
                yield (int) $result->getId();
            }
        }
    }

    /**
     * How many changes the index has: about as many as a full listing of Gerrit finds, for its progress.
     */
    public function count(): int
    {
        return ElasticUtility::getIndexByName('review')->count();
    }

    /**
     * Removes changes from the index.
     *
     * @param list<int> $numbers
     *
     * @return int the number of removed changes, numbers not in the index are ignored
     */
    public function remove(array $numbers): int
    {
        if ([] === $numbers) {
            return 0;
        }
        $response = ElasticUtility::getIndexByName('review')->deleteByQuery(
            new Query(['query' => ['ids' => ['values' => array_values(array_map('strval', $numbers))]]]),
            ['refresh' => 'true', 'conflicts' => 'proceed'],
        )->getData();

        return (int) ($response['deleted'] ?? 0);
    }
}
