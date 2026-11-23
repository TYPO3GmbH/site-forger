<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Sprint;

use App\Entity\SprintBoard;
use App\Entity\SprintBoardCategory;
use App\Repository\SprintBoardCategoryRepository;
use App\Repository\SprintBoardRepository;
use App\Security\SprintBoardVoter;
use App\Sprint\VisibleBoards;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The categories and boards of the overview and the menu, see VisibleBoards.
 */
final class VisibleBoardsTest extends TestCase
{
    private SprintBoardCategory $core;

    private SprintBoardCategory $hidden;

    private SprintBoardCategory $empty;

    private SprintBoard $public;

    private SprintBoard $restricted;

    protected function setUp(): void
    {
        $this->core = (new SprintBoardCategory())->setName('Core')->setIdentifier('core');
        $this->hidden = (new SprintBoardCategory())->setName('Hidden')->setIdentifier('hidden');
        $this->empty = (new SprintBoardCategory())->setName('Empty')->setIdentifier('empty');
        $this->public = (new SprintBoard())->setName('Public');
        $this->restricted = (new SprintBoard())->setName('Restricted')->setRestricted(true);
    }

    public function testAUserSeesOnlyTheCategoriesWithABoardTheUserSees(): void
    {
        $visible = $this->visible(manager: false);

        self::assertSame([$this->core], array_column($visible, 'category'));
        self::assertSame([$this->public], $visible[0]['boards']);
    }

    public function testAManagerSeesEveryCategoryToAddBoards(): void
    {
        $visible = $this->visible(manager: true);

        self::assertSame([$this->core, $this->hidden, $this->empty], array_column($visible, 'category'));
        self::assertSame([$this->restricted], $visible[1]['boards']);
        self::assertSame([], $visible[2]['boards']);
    }

    /**
     * @return list<array{category: SprintBoardCategory, boards: list<SprintBoard>}>
     */
    private function visible(bool $manager): array
    {
        $categories = $this->createStub(SprintBoardCategoryRepository::class);
        $categories->method('findBy')->willReturn([$this->core, $this->hidden, $this->empty]);
        $boards = $this->createStub(SprintBoardRepository::class);
        $boards->method('findBy')->willReturnCallback(fn (array $criteria): array => match ($criteria['category']) {
            $this->core => [$this->public],
            $this->hidden => [$this->restricted],
            default => [],
        });
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(
            static fn (string $attribute, mixed $subject = null): bool => SprintBoardVoter::MANAGER === $attribute
                ? $manager
                : $manager || ($subject instanceof SprintBoard && !$subject->isRestricted()),
        );

        return (new VisibleBoards($categories, $boards, $authorization))->byCategory();
    }
}
