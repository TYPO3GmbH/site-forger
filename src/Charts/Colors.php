<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Charts;

final class Colors
{
    public const BLUE = 'blue';
    public const GREEN = 'green';
    public const GREY = 'grey';
    public const ORANGE = 'orange';
    public const RED = 'red';
    public const YELLOW = 'yellow';
    private static array $colors = [
        self::BLUE => [39, 162, 252],
        self::GREEN => [55, 189, 116],
        self::GREY => [210, 210, 210],
        self::ORANGE => [255, 135, 0],
        self::RED => [255, 48, 79],
        self::YELLOW => [255, 193, 7],
    ];

    public static function getAsRgb(string $color): string
    {
        return 'rgb(' . implode(',', self::$colors[$color]) . ')';
    }

    public static function getAsRgba(string $color, float $transparency = 0.6): string
    {
        return 'rgba(' . implode(',', self::$colors[$color]) . ',' . max(0, min(1, $transparency)) . ')';
    }
}
