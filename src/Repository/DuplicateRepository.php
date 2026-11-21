<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Repository;

use App\Document\DuplicateCheck;
use App\Document\DuplicatePair;
use App\Duplicates\DuplicateGroups;
use App\Elastic\Indices;
use App\Elastic\SearchResult;
use Elastica\Document;
use Elastica\Query;

/**
 * The possible duplicates among the open issues in the duplicate index, see DuplicateImport.
 */
final readonly class DuplicateRepository
{
    private const int SCROLL_SIZE = 5000;

    public function __construct(
        private Indices $indices,
    ) {
    }

    /**
     * Every check, by the number of the issue.
     *
     * @return array<int, DuplicateCheck>
     */
    public function checks(): array
    {
        $search = $this->indices->duplicates()->createSearch([
            'query' => ['term' => ['kind' => DuplicateCheck::KIND]],
            'size' => self::SCROLL_SIZE,
        ]);
        $checks = [];
        foreach ($search->scroll('1m') as $resultSet) {
            foreach ($resultSet->getResults() as $result) {
                $check = DuplicateCheck::fromDocument($result->getData());
                $checks[$check->issue] = $check;
            }
        }

        return $checks;
    }

    /**
     * The pairs, the most similar first.
     *
     * @return SearchResult<DuplicatePair>
     */
    public function pairs(int $from, int $size): SearchResult
    {
        $resultSet = $this->indices->duplicates()->createSearch([
            // Pairs stored under a lower minimum stay until their check is renewed
            'query' => ['bool' => ['filter' => [
                ['term' => ['kind' => DuplicatePair::KIND]],
                ['range' => ['similarity' => ['gte' => DuplicateGroups::MIN_SIMILARITY]]],
            ]]],
            'sort' => [['similarity' => 'desc'], ['issues' => 'desc']],
            'from' => $from,
            'size' => $size,
            'track_total_hits' => true,
        ])->search();

        return SearchResult::of($resultSet, DuplicatePair::fromDocument(...));
    }

    /**
     * Writes the checks of the issues and their pairs. The pairs the issues had before are removed first.
     *
     * @param list<DuplicateCheck> $checks
     * @param list<DuplicatePair>  $pairs
     */
    public function replace(array $checks, array $pairs): void
    {
        if ([] === $checks) {
            return;
        }
        $issues = array_map(static fn (DuplicateCheck $check): int => $check->issue, $checks);
        $this->deleteWhere(['bool' => ['filter' => [
            ['term' => ['kind' => DuplicatePair::KIND]],
            ['terms' => ['issues' => $issues]],
        ]]]);
        $documents = [
            ...array_map(static fn (DuplicateCheck $check): Document => new Document(
                $check->id(),
                $check->toDocument(),
            ), $checks),
            ...array_map(static fn (DuplicatePair $pair): Document => new Document(
                $pair->id(),
                $pair->toDocument(),
            ), $pairs),
        ];
        $this->indices->duplicates()->addDocuments($documents);
    }

    /**
     * Removes the checks and the pairs of the issues.
     *
     * @param list<int> $issues
     */
    public function remove(array $issues): void
    {
        if ([] !== $issues) {
            $this->deleteWhere(['terms' => ['issues' => $issues]]);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function deleteWhere(array $query): void
    {
        // Waits for the deletion, so the documents written next are not caught by it
        $this->indices->duplicates()->deleteByQuery(new Query(['query' => $query]), [
            'refresh' => 'true',
            'conflicts' => 'proceed',
        ]);
    }
}
