<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\Interest;
use App\Enum\IssueSortEnum;
use App\Enum\ReviewSortEnum;
use App\Repository\InterestRepository;
use App\Sprint\BoardFilter;
use App\Sprint\ReviewBoardFilter;
use App\Sprint\ReviewBoards;
use App\Sprint\SprintBoards;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * An interest of the user as a board of its own: its issues in the columns of a sprint board, or the changes that
 * touch its paths in the columns of the review board, in the order of the parameter "sort". Only its owner sees it.
 */
#[Route(path: '/my/interests/{id<\d+>}/board', name: 'my_interest_board')]
#[IsGranted('ROLE_USER')]
class InterestBoardController extends AbstractController
{
    public const string ISSUES = 'issues';
    public const string REVIEWS = 'reviews';

    public function __construct(
        private readonly InterestRepository $interests,
        private readonly SprintBoards $sprintBoards,
        private readonly ReviewBoards $reviewBoards,
    ) {
    }

    #[Route(
        path: '/{view}',
        name: '',
        requirements: ['view' => 'issues|reviews'],
        defaults: ['view' => null],
        methods: ['GET'],
    )]
    public function board(Request $request, int $id, ?string $view = null): Response
    {
        $interest = $this->interest($id);
        $view ??= [] === $interest->getCategories() ? self::REVIEWS : self::ISSUES;

        return $this->render('my/interest_board.html.twig', ['interest' => $interest, 'view' => $view]
            + $this->parts($interest, $view, $request->query->getString('sort')));
    }

    /**
     * The parts of the board that change, as HTML: the board loads them again every minute, see
     * assets/ts/sprint/live-board.ts.
     */
    #[Route(path: '/{view}/columns', name: '_columns', requirements: ['view' => 'issues|reviews'], methods: ['GET'])]
    public function columns(Request $request, int $id, string $view): Response
    {
        $template = self::ISSUES === $view ? 'sprint/_board_parts.html.twig' : 'sprint/_review_parts.html.twig';

        return $this->render($template, $this->parts($this->interest($id), $view, $request->query->getString('sort')));
    }

    /**
     * @return array<string, mixed>
     */
    private function parts(Interest $interest, string $view, string $sort): array
    {
        if (self::REVIEWS === $view) {
            $reviewSort = ReviewSortEnum::fromRequest($sort, false, ReviewSortEnum::LEAST_UPDATED);

            $filter = new ReviewBoardFilter();
            $filter->paths = $interest->getPaths();

            return [
                // An interest without paths has no changes, not all of them
                'cards' => $filter->hasFilter() ? $this->reviewBoards->cards($filter, $reviewSort) : [],
                'sort' => $reviewSort,
                'sortOptions' => ReviewSortEnum::options(false),
            ];
        }
        $issueSort = IssueSortEnum::fromRequest($sort, false, IssueSortEnum::UPDATED);
        $filter = new BoardFilter();
        $filter->categories = $interest->getCategories();
        $cards = [] === $filter->categories ? [] : $this->sprintBoards->cardsOf($filter->toQuery(), $issueSort);

        return [
            'cards' => $cards,
            'progress' => SprintBoards::progress($cards),
            'sort' => $issueSort,
            'sortOptions' => IssueSortEnum::options(false),
        ];
    }

    private function interest(int $id): Interest
    {
        $interest = $this->interests->ofOwnerById((string) $this->getUser()?->getUserIdentifier(), $id);
        if (null === $interest) {
            throw $this->createNotFoundException('You have no such interest.');
        }

        return $interest;
    }
}
