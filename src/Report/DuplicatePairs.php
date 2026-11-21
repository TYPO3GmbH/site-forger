<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Report;

use App\Document\DuplicatePair;
use App\Document\Issue;
use App\Elastic\SearchResult;
use App\Repository\DuplicateRepository;
use App\Repository\IssueRepository;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * The pairs of open issues that may be duplicates, the most similar first, as DuplicateImport found them.
 */
final readonly class DuplicatePairs
{
    public function __construct(
        private DuplicateRepository $duplicates,
        private IssueRepository $issues,
    ) {
    }

    /**
     * @return SearchResult<DuplicatePairRow>
     */
    public function page(int $from, int $size): SearchResult
    {
        $pairs = $this->duplicates->pairs($from, $size);
        $numbers = [];
        foreach ($pairs as $pair) {
            array_push($numbers, $pair->lower, $pair->higher);
        }

        $rows = self::rows($pairs->getValues(), $this->issues->byIds($numbers));

        return SearchResult::ofDocuments($rows, $pairs->total);
    }

    /**
     * Each pair with its issues, the newer one first. A pair whose issue left the index since is left out.
     *
     * @param list<DuplicatePair>            $pairs
     * @param ReadableCollection<int, Issue> $issues by their number
     *
     * @return list<DuplicatePairRow>
     */
    public static function rows(array $pairs, ReadableCollection $issues): array
    {
        $rows = [];
        foreach ($pairs as $pair) {
            $newer = $issues->get($pair->higher);
            $older = $issues->get($pair->lower);
            if (null !== $newer && null !== $older) {
                $rows[] = new DuplicatePairRow($pair, $newer, $older);
            }
        }

        return $rows;
    }
}
