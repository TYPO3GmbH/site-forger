<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Entity\SprintBoardCategory;

/**
 * The order of the categories on the sprint overview, as a manager changes it there.
 */
final class CategoryOrder
{
    public const string UP = 'up';

    public const string DOWN = 'down';

    /**
     * Moves a category one place up or down and numbers all of them in their new order, so each has a place of
     * its own. The first one stays when it moves up, the last one when it moves down.
     *
     * @param list<SprintBoardCategory> $categories in the order of the overview
     *
     * @return list<SprintBoardCategory> in their new order
     */
    public static function move(array $categories, SprintBoardCategory $category, string $direction): array
    {
        $from = array_search($category, $categories, true);
        $to = false === $from ? false : $from + (self::UP === $direction ? -1 : 1);
        if (false !== $from && false !== $to && isset($categories[$to])) {
            [$categories[$from], $categories[$to]] = [$categories[$to], $categories[$from]];
        }
        foreach ($categories as $place => $each) {
            $each->setSorting($place + 1);
        }

        return array_values($categories);
    }
}
