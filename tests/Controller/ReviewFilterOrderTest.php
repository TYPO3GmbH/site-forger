<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Controller;

use App\Controller\ReviewController;
use PHPUnit\Framework\TestCase;

final class ReviewFilterOrderTest extends TestCase
{
    public function testTheFiltersStandInTheOrderTheyWereAskedFor(): void
    {
        $answer = ['Updated on' => ['u'], 'Owner' => ['o'], 'Size' => ['s'], 'Owner selected' => ['x']];

        self::assertSame(
            ['Size' => ['s'], 'Owner' => ['o'], 'Updated on' => ['u'], 'Owner selected' => ['x']],
            ReviewController::inOrderOf($answer, ['Size', 'Releases', 'Owner', 'Updated on'])
        );
    }

    public function testWithoutAnswerThereIsNothingToOrder(): void
    {
        self::assertSame([], ReviewController::inOrderOf([], ['Size']));
    }
}
