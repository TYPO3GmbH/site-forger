<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Enum;

enum TrackerIdEnum: string
{
    case BUG = '1';
    case EPIC = '10';
    case FEATURE = '2';
    case STORY = '6';
    case TASK = '4';
}
