<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Duplicates;

use App\Document\Issue;
use App\Repository\IssueRepository;
use App\Search\DuplicateQuery;

/**
 * The open issues most like a given one, for its duplicates page and the button on its page.
 *
 * Scores of Elasticsearch only compare hits of one query. So each score is put in relation to the score the
 * issue itself reaches with the query: 100 % would be an issue with the same wording. That measures the shared
 * wording, it is not a probability. It needs one shard, see config/Elasticorn/issue/IndexConfiguration.yaml.
 *
 * One direction is not enough: the query takes the words of one issue, and a short issue shares most of its few
 * words with a long one, but not the other way round. So each hit is checked from its own side, too, and the
 * similarity is the mean of both. Both issues then show the same value on their pages.
 */
final readonly class DuplicateSearch
{
    /**
     * How many of the most similar issues the search checks. DuplicateGroups offers those that reach its
     * MIN_SIMILARITY.
     */
    public const int CANDIDATES = 50;

    public function __construct(
        private IssueRepository $issues,
    ) {
    }

    /**
     * @return list<DuplicateHit> the most similar first
     */
    public function find(int $id): array
    {
        $forward = self::rank($id, $this->issues->scored([
            'query' => DuplicateQuery::query($id),
            // One more for the issue itself, the measure of the similarity
            'size' => self::CANDIDATES + 1,
        ]));
        $backward = $this->issues->scoredMany(array_map(
            static fn (DuplicateHit $hit): array => [
                'query' => DuplicateQuery::between($hit->issue->id, $id),
                'size' => 2,
            ],
            $forward,
        ));

        return self::check($id, $forward, $backward);
    }

    /**
     * Combines each hit with the search from its own side, the most similar first.
     *
     * @param list<DuplicateHit>              $forward  see rank()
     * @param list<list<array{Issue, float}>> $backward the search of each hit for the issue, in the same order
     *
     * @return list<DuplicateHit>
     */
    public static function check(int $id, array $forward, array $backward): array
    {
        $checked = [];
        foreach ($forward as $position => $hit) {
            $back = self::rank($hit->issue->id, $backward[$position] ?? []);
            $checked[] = DuplicateHit::checked($hit->issue, (int) $hit->forward, (int) ($back[0]->forward ?? 0));
        }
        usort($checked, static fn (DuplicateHit $a, DuplicateHit $b): int => $b->similarity <=> $a->similarity);

        return $checked;
    }

    /**
     * The hits without the issue itself, each with its share of the score the issue reaches.
     *
     * @param list<array{Issue, float}> $scored see IssueRepository::scored()
     *
     * @return list<DuplicateHit> with the similarity in one direction, as forward
     */
    public static function rank(int $id, array $scored): array
    {
        $own = null;
        foreach ($scored as [$issue, $score]) {
            if ($id === $issue->id) {
                $own = $score;
            }
        }
        // An issue without a word that sets it apart: the query has no term and does not even find the issue
        if (null === $own || $own <= 0.0) {
            return [];
        }

        $ranked = [];
        foreach ($scored as [$issue, $score]) {
            if ($id !== $issue->id) {
                $similarity = (int) round(100 * min(1.0, $score / $own));
                $ranked[] = new DuplicateHit($issue, $similarity, $similarity);
            }
        }

        return $ranked;
    }
}
