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
use Doctrine\Common\Collections\ReadableCollection;

/**
 * The duplicates page in its groups: the issues Forge relates as duplicates first, then the similar issues the
 * search found. Only open issues count, as in the search.
 */
final readonly class DuplicateGroups
{
    public const string DUPLICATES = 'This issue duplicates it';
    public const string DUPLICATED_BY = 'Duplicate of this issue';

    /**
     * The similarity in percent an issue needs to be offered as a duplicate. Known duplicates reached 40 to 50 %,
     * unrelated issues mostly stayed below 20 %. Below 50 % the list of pairs held too many issues that only share
     * a topic.
     */
    public const int MIN_SIMILARITY = 50;

    /**
     * @param list<DuplicateHit> $marked  the issues Forge relates, with their similarity when the search found them
     * @param list<DuplicateHit> $similar the hits that reach MIN_SIMILARITY, the most similar first
     */
    public function __construct(
        public array $marked = [],
        public array $similar = [],
    ) {
    }

    /**
     * @param list<DuplicateHit>             $hits   see DuplicateSearch::find()
     * @param ReadableCollection<int, Issue> $issues the issues Forge relates, by number, as far as the index has
     *                                               them; closed ones are left out
     */
    public static function of(Issue $issue, array $hits, ReadableCollection $issues): self
    {
        $relations = self::relations($issue);
        $found = [];
        $similar = [];
        foreach ($hits as $hit) {
            $id = $hit->issue->id;
            $found[$id] = $hit;
            if (!isset($relations[$id]) && $hit->similarity >= self::MIN_SIMILARITY) {
                $similar[] = $hit;
            }
        }

        $marked = [];
        foreach ($relations as $id => $relation) {
            $hit = $found[$id] ?? self::openIssue($issues->get($id));
            if (null !== $hit) {
                $marked[] = $hit->withRelation($relation);
            }
        }

        return new self($marked, $similar);
    }

    /**
     * How Forge relates each issue to this one, by its number.
     *
     * @return array<int, string>
     */
    public static function relations(Issue $issue): array
    {
        $relations = [];
        foreach ($issue->duplicates as $id) {
            $relations[$id] = self::DUPLICATES;
        }
        foreach ($issue->duplicatedBy as $id) {
            $relations[$id] ??= self::DUPLICATED_BY;
        }

        return $relations;
    }

    /**
     * How many issues the page offers as duplicates: the marked and the similar ones.
     */
    public function offered(): int
    {
        return count($this->marked) + count($this->similar);
    }

    private static function openIssue(?Issue $issue): ?DuplicateHit
    {
        $open = null !== $issue && true !== $issue->statusEnum()?->isClosed();

        return $open ? new DuplicateHit($issue, null) : null;
    }
}
