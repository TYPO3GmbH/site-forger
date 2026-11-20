<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Search;

use App\Enum\IssueStatusEnum;

/**
 * How many issues of a list have a status, for its chip in MyWork.
 */
final readonly class IssueStatusCount
{
    public function __construct(
        public IssueStatusEnum $status,
        public int $count,
    ) {
    }

    /**
     * @param array<array-key, int> $counts how many issues have each status, by its id
     *
     * @return list<self> the states with issues, in the order of the workflow
     */
    public static function listOf(array $counts): array
    {
        $list = [];
        foreach (IssueStatusEnum::cases() as $status) {
            $count = $counts[$status->value] ?? 0;
            if ($count > 0) {
                $list[] = new self($status, $count);
            }
        }

        return $list;
    }
}
