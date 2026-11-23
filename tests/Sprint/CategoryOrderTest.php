<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Sprint;

use App\Entity\SprintBoardCategory;
use App\Sprint\CategoryOrder;
use PHPUnit\Framework\TestCase;

final class CategoryOrderTest extends TestCase
{
    /** @var list<SprintBoardCategory> */
    private array $categories;

    protected function setUp(): void
    {
        $this->categories = array_map(
            static fn (string $name): SprintBoardCategory => (new SprintBoardCategory())->setName($name),
            ['Initiatives', 'Reviews', 'Core'],
        );
    }

    public function testACategoryMovesOnePlaceAndAllGetAPlaceOfTheirOwn(): void
    {
        $moved = CategoryOrder::move($this->categories, $this->categories[2], CategoryOrder::UP);

        self::assertSame(['Initiatives', 'Core', 'Reviews'], $this->names($moved));
        self::assertSame([1, 2, 3], array_map(static fn (SprintBoardCategory $each) => $each->getSorting(), $moved));
    }

    public function testACategoryMovesDown(): void
    {
        $moved = CategoryOrder::move($this->categories, $this->categories[0], CategoryOrder::DOWN);

        self::assertSame(['Reviews', 'Initiatives', 'Core'], $this->names($moved));
    }

    public function testTheFirstStaysWhenItMovesUpAndTheLastWhenItMovesDown(): void
    {
        $up = CategoryOrder::move($this->categories, $this->categories[0], CategoryOrder::UP);
        $down = CategoryOrder::move($this->categories, $this->categories[2], CategoryOrder::DOWN);

        self::assertSame(['Initiatives', 'Reviews', 'Core'], $this->names($up));
        self::assertSame(['Initiatives', 'Reviews', 'Core'], $this->names($down));
    }

    /**
     * @param list<SprintBoardCategory> $categories
     *
     * @return list<string>
     */
    private function names(array $categories): array
    {
        return array_map(static fn (SprintBoardCategory $each): string => (string) $each->getName(), $categories);
    }
}
