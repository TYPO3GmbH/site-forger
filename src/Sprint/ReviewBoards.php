<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Document\Change;
use App\Enum\ReviewStatusEnum;
use App\Repository\ChangeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\ReadableCollection;

/**
 * The open changes of a type ("[BUGFIX]", "[TASK]", "[FEATURE]"), grouped by the votes they still need.
 */
final readonly class ReviewBoards
{
    public const array TYPES = ['bugfix' => 'Bugfixes', 'task' => 'Tasks', 'feature' => 'Features'];

    private const array GROUPS = ['missesBoth', 'missesCodeReview', 'missesTesting', 'hasPlusOne', 'mergeConflict', 'blocked'];
    private const int MAX_CHANGES = 500;

    public function __construct(
        private ChangeRepository $changes,
    ) {
    }

    /**
     * @return array<string, ReadableCollection<int, Change>> by group, empty for an unknown type
     */
    public function cards(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            return [];
        }
        $cards = array_fill_keys(self::GROUPS, []);
        $found = $this->changes->search([
            'query' => self::query($type),
            'size' => self::MAX_CHANGES,
            'sort' => [['updated_on' => ['order' => 'asc']]],
        ]);
        foreach ($found as $change) {
            $cards[self::group($change)][] = $change;
        }

        return array_map(static fn (array $group): ArrayCollection => new ArrayCollection($group), $cards);
    }

    /**
     * @return list<array{id: string, count: int, name: string, active: bool}>
     */
    public function menu(string $active): array
    {
        $menu = [];
        foreach (self::TYPES as $type => $name) {
            $count = $this->changes->count(['query' => self::query($type)]);
            $menu[] = ['id' => $type, 'count' => $count, 'name' => $name, 'active' => $type === $active];
        }

        return $menu;
    }

    /**
     * @return array<string, mixed>
     */
    private static function query(string $type): array
    {
        return ['bool' => [
            'must' => [
                ['terms' => ['reviewtags' => [strtoupper($type)]]],
                ['query_string' => ['default_field' => 'reviewstatus', 'query' => ReviewStatusEnum::NEW->value]],
            ],
            'must_not' => [['term' => ['work_in_progress' => true]]],
        ]];
    }

    public static function group(Change $change): string
    {
        if (false === $change->mergeable) {
            return 'mergeConflict';
        }
        $against = $change->negativeVotes(Change::LABEL_REVIEW) + $change->negativeVotes(Change::LABEL_VERIFICATION);
        if ($against > 0) {
            return 'blocked';
        }
        $reviewed = $change->positiveVotes(Change::LABEL_REVIEW) > 0;
        $verified = $change->positiveVotes(Change::LABEL_VERIFICATION) > 0;

        return match (true) {
            $reviewed && $verified => 'hasPlusOne',
            $reviewed => 'missesTesting',
            $verified => 'missesCodeReview',
            default => 'missesBoth',
        };
    }
}
