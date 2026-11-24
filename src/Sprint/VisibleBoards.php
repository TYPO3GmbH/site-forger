<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Sprint;

use App\Entity\SprintBoard;
use App\Entity\SprintBoardCategory;
use App\Repository\SprintBoardCategoryRepository;
use App\Repository\SprintBoardRepository;
use App\Security\SprintBoardVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The categories and boards the user sees, for the sprint overview. A restricted board only for the
 * people it is shared with, see SprintBoardVoter. A category only with a board the user sees; managers see every
 * category, also an empty one, to add boards to it.
 */
final readonly class VisibleBoards
{
    public function __construct(
        private SprintBoardCategoryRepository $categories,
        private SprintBoardRepository $boards,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @return list<array{category: SprintBoardCategory, boards: list<SprintBoard>}> in the order of the overview
     */
    public function byCategory(): array
    {
        $manager = $this->authorization->isGranted(SprintBoardVoter::MANAGER);
        $visible = [];
        foreach ($this->categories->findBy([], ['sorting' => 'ASC', 'name' => 'ASC']) as $category) {
            $boards = array_values(array_filter(
                $this->boards->findBy(['category' => $category], ['sorting' => 'ASC', 'name' => 'ASC']),
                fn (SprintBoard $board): bool => $this->authorization->isGranted(SprintBoardVoter::VIEW, $board),
            ));
            if ($manager || [] !== $boards) {
                $visible[] = ['category' => $category, 'boards' => $boards];
            }
        }

        return $visible;
    }
}
