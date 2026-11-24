<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Import;

use App\Enum\SystemCheckEnum;

/**
 * The last comparison of the issues of the index with those of Forge, see ForgeImport::reconcile(). The numbers
 * are those before the run fixed what it found.
 */
final readonly class IssueComparison
{
    /**
     * @param \DateTimeImmutable      $at         when the run compared them
     * @param int                     $forge      the issues of the project on Forge
     * @param int                     $index      the issues of the project in the index
     * @param int                     $ranges     the ranges of numbers the run listed because they differ
     * @param int                     $added      the issues the run loaded because the index lacked them
     * @param int                     $removed    the issues the run removed: Forge does not show them
     * @param \DateTimeImmutable|null $alikeSince since when the comparisons find the same numbers, null while they
     *                                            differ
     */
    public function __construct(
        public \DateTimeImmutable $at,
        public int $forge,
        public int $index,
        public int $ranges = 0,
        public int $added = 0,
        public int $removed = 0,
        public ?\DateTimeImmutable $alikeSince = null,
    ) {
    }

    /**
     * The comparison of a run, after the one before.
     */
    public static function of(
        ?self $before,
        \DateTimeImmutable $at,
        int $forge,
        int $index,
        int $ranges = 0,
        int $added = 0,
        int $removed = 0,
    ): self {
        $alikeSince = null;
        if ($forge === $index) {
            $alikeSince = null !== $before && $before->isAlike() ? $before->alikeSince : $at;
        }

        return new self($at, $forge, $index, $ranges, $added, $removed, $alikeSince);
    }

    public function isAlike(): bool
    {
        return $this->forge === $this->index;
    }

    public function difference(): int
    {
        return abs($this->forge - $this->index);
    }

    /**
     * How far the index matches Forge, from 0 to 1: the smaller number of issues of the larger. Forge can count by
     * numbers, so each run compares all issues at once: there is no round to show, only how full the index is.
     */
    public function share(): float
    {
        $larger = max($this->forge, $this->index);

        return 0 === $larger ? 1.0 : min($this->forge, $this->index) / $larger;
    }

    public function result(): SystemCheckEnum
    {
        return $this->isAlike() ? SystemCheckEnum::OK : SystemCheckEnum::ATTENTION;
    }
}
