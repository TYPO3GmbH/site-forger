<?php

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
    public const ORANGE = 'orange';
    public const RED = 'RED';
    public const YELLOW = 'yellow';

    protected static $colors = [
        self::BLUE => [54, 162, 235],
        self::GREEN => [75, 192, 192],
        self::ORANGE => [255, 159, 64],
        self::RED => [255, 99, 132],
        self::YELLOW => [255, 205, 86],
    ];

    public static function getAsRgb(string $color): string
    {
        return 'rgb(' . implode(',', static::$colors[$color]) . ')';
    }

    public static function getAsRgba(string $color, float $transparency = 0.6): string
    {
        return 'rgba(' . implode(',', static::$colors[$color]) . ',' . max(0, min(1, $transparency)) . ')';
    }
}
