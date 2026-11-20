<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Elastic\Indices;
use App\Enum\ReviewStatusEnum;

/**
 * The open changes of a type ("[BUGFIX]", "[TASK]", "[FEATURE]"), grouped by the votes they still need.
 */
final readonly class ReviewBoards
{
    public const array TYPES = ['bugfix' => 'Bugfixes', 'task' => 'Tasks', 'feature' => 'Features'];

    private const array GROUPS = ['missesBoth', 'missesCodeReview', 'missesTesting', 'hasPlusOne', 'mergeConflict', 'blocked'];
    private const int MAX_CHANGES = 500;

    public function __construct(
        private Indices $indices,
    ) {
    }

    /**
     * @return array<string, list<array<string, mixed>>> by group, empty for an unknown type
     */
    public function cards(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            return [];
        }
        $cards = array_fill_keys(self::GROUPS, []);
        $resultSet = $this->indices->reviews()->createSearch([
            'query' => self::query($type),
            'size' => self::MAX_CHANGES,
            'sort' => [['updated_on' => ['order' => 'asc']]],
        ])->search();
        foreach ($resultSet->getResults() as $result) {
            $change = $result->getSource();
            $cards[self::group($change)][] = $change;
        }

        return $cards;
    }

    /**
     * @return list<array{id: string, count: int, name: string, active: bool}>
     */
    public function menu(string $active): array
    {
        $menu = [];
        foreach (self::TYPES as $type => $name) {
            $count = $this->indices->reviews()->createSearch(['query' => self::query($type), 'size' => 0])->search()->getTotalHits();
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

    /**
     * @param array<string, mixed> $change
     */
    public static function group(array $change): string
    {
        if ('no' === $change['mergeable']) {
            return 'mergeConflict';
        }
        if ($change['negative_reviews'] > 0 || $change['negative_verifications'] > 0) {
            return 'blocked';
        }
        $reviewed = $change['positive_reviews'] > 0;
        $verified = $change['positive_verifications'] > 0;

        return match (true) {
            $reviewed && $verified => 'hasPlusOne',
            $reviewed => 'missesTesting',
            $verified => 'missesCodeReview',
            default => 'missesBoth',
        };
    }
}
