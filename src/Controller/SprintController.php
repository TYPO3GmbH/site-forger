<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\SprintBoard;
use App\Entity\SprintBoardCategory;
use App\Enum\BoardTypeEnum;
use App\Enum\IssueSortEnum;
use App\Enum\ReviewSortEnum;
use App\Form\BoardAccessFields;
use App\Form\SprintBoardWizard;
use App\Repository\SprintBoardCategoryRepository;
use App\Repository\SprintBoardRepository;
use App\Security\SprintBoardVoter;
use App\Sprint\BoardCount;
use App\Sprint\BoardFilter;
use App\Sprint\BoardMembers;
use App\Sprint\ReviewBoardFilter;
use App\Sprint\ReviewBoards;
use App\Sprint\SprintBoards;
use App\Sprint\VisibleBoards;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class SprintController extends AbstractController
{
    public const string DELETE_TOKEN_ID = 'sprint-delete';
    public const string RENAME_TOKEN_ID = 'sprint-rename';

    /**
     * The identifier of a board in its address, see SprintBoard::$identifier.
     */
    /**
     * The UUID of a board in the addresses that change it, see SprintBoard::$uuid.
     */
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    private const string BOARD_SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /**
     * What the cards of a board are, see boardParts().
     */
    private const string ISSUES = 'issues';

    private const string CHANGES = 'changes';

    public function __construct(
        private readonly SprintBoards $sprintBoards,
        private readonly ReviewBoards $reviewBoards,
        private readonly SprintBoardCategoryRepository $sprintBoardCategoryRepository,
        private readonly SprintBoardRepository $sprintBoardRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly VisibleBoards $visibleBoards,
        private readonly BoardMembers $boardMembers,
    ) {
    }

    /**
     * The sprint boards the user sees by category, each with how far it has got, see VisibleBoards.
     */
    #[Route(path: '/boards', name: 'sprint_overview', priority: 1)]
    public function overview(): Response
    {
        $categories = [];
        foreach ($this->visibleBoards->byCategory() as ['category' => $category, 'boards' => $found]) {
            $boards = [];
            foreach ($found as $board) {
                $boards[] = ['board' => $board] + $this->countsOf($board);
            }
            $categories[] = ['category' => $category, 'boards' => $boards];
        }

        return $this->render('sprint/overview.html.twig', ['categories' => $categories]);
    }

    /**
     * How far a board has got, for its card on the overview: its issues by state, or its changes by the votes they
     * still need. No count for issues if the query of the board cannot be read.
     *
     * @return array{count: BoardCount|null, changes: array<string, int>|null}
     */
    private function countsOf(SprintBoard $board): array
    {
        if (BoardTypeEnum::REVIEWS === $board->getType()) {
            return ['count' => null, 'changes' => $this->reviewBoards->counts($board->getReviewFilter())];
        }
        try {
            return ['count' => $this->sprintBoards->counts((string) $board->getQuery()), 'changes' => null];
        } catch (ParseException) {
            return ['count' => null, 'changes' => null];
        }
    }

    /**
     * The addresses of the boards began with "/sprint" before the boards of reviews: they lead to the new ones.
     */
    #[Route(
        path: '/sprint/{rest}',
        name: 'sprint_legacy',
        requirements: ['rest' => '.*'],
        defaults: ['rest' => ''],
        methods: ['GET'],
    )]
    public function legacy(Request $request, string $rest): Response
    {
        $query = $request->getQueryString();
        $path = rtrim('/boards/' . $rest, '/');

        return $this->redirect(
            $request->getBaseUrl() . $path . (null === $query ? '' : '?' . $query),
            Response::HTTP_MOVED_PERMANENTLY,
        );
    }

    #[Route(path: '/boards/create', name: 'sprint_create')]
    #[IsGranted('ROLE_FORGER_MANAGEMENT')]
    public function createSprint(Request $request): Response
    {
        return $this->handleBoardForm($request, $this->newBoard($request));
    }

    /**
     * A new board of the type and in the category the link it was created from names (?type=reviews,
     * ?category=identifier).
     */
    private function newBoard(Request $request): SprintBoard
    {
        $board = (new SprintBoard())->setType(self::typeOf($request));
        $identifier = $request->query->getString('category');
        if ('' !== $identifier) {
            $board->setCategory($this->sprintBoardCategoryRepository->findOneBy(['identifier' => $identifier]));
        }

        return $board;
    }

    private static function typeOf(Request $request): BoardTypeEnum
    {
        return BoardTypeEnum::tryFrom($request->query->getString('type')) ?? BoardTypeEnum::ISSUES;
    }

    #[Route(path: '/boards/edit/{uuid}', name: 'sprint_edit', requirements: ['uuid' => self::UUID])]
    #[IsGranted('ROLE_FORGER_MANAGEMENT')]
    public function editSprint(Request $request, string $uuid): Response
    {
        $sprint = $this->sprintBoardRepository->findOneBy(['uuid' => $uuid]);
        if (null === $sprint) {
            return $this->redirectToRoute('sprint_create');
        }

        $reviews = BoardTypeEnum::REVIEWS === $sprint->getType();
        if ($reviews || null !== BoardFilter::fromYaml($sprint->getQuery())) {
            return $this->handleBoardForm($request, $sprint);
        }
        // A query written by hand opens with the filters it comes closest to, saving replaces it
        $import = $this->importHandWrittenQuery($sprint);
        $handWrittenQuery = (string) $sprint->getQuery();
        $sprint->setQuery($import['filter']->toYaml());

        return $this->handleBoardForm($request, $sprint, [
            'handWrittenQuery' => $handWrittenQuery,
            'droppedClauses' => $import['dropped'],
        ]);
    }

    /**
     * What the filters of the form of a board find right now, grouped like on the board: issues, or with
     * "type=reviews" changes.
     */
    #[Route(path: '/boards/wizard/preview', name: 'sprint_wizard_preview', methods: ['POST'])]
    #[IsGranted('ROLE_FORGER_MANAGEMENT')]
    public function previewSprint(Request $request): Response
    {
        $type = self::typeOf($request);
        $form = $this->createForm(SprintBoardWizard::class, (new SprintBoard())->setType($type));
        $form->handleRequest($request);
        if (BoardTypeEnum::REVIEWS === $type) {
            $filter = $form->isSubmitted() ? $form->get('reviewFilter')->getData() : null;
            if (!$filter instanceof ReviewBoardFilter || !$filter->hasFilter()) {
                return $this->render('sprint/_review_preview.html.twig', ['hasFilter' => false]);
            }
            $sort = $form->get('reviewSort')->getData();

            return $this->render('sprint/_review_preview.html.twig', [
                'hasFilter' => true,
                'cards' => $this->reviewBoards->cards(
                    $filter,
                    $sort instanceof ReviewSortEnum ? $sort : ReviewSortEnum::LEAST_UPDATED,
                ),
            ]);
        }
        $filter = $form->isSubmitted() ? $form->get('query')->getNormData() : null;
        if (!$filter instanceof BoardFilter || !$filter->hasFilter()) {
            return $this->render('sprint/_wizard_preview.html.twig', ['hasFilter' => false]);
        }

        $preview = $this->sprintBoards->preview($filter);

        return $this->render('sprint/_wizard_preview.html.twig', [
            'hasFilter' => true,
            'columns' => $preview->columns,
            'total' => $preview->total,
            'issues' => $preview->cards,
        ]);
    }

    /**
     * Creates or edits a board with its one form, see templates/sprint/wizard.html.twig.
     *
     * @param array<string, mixed> $wizardContext the query written by hand the form replaces, if any
     */
    private function handleBoardForm(Request $request, SprintBoard $sprint, array $wizardContext = []): Response
    {
        $isNew = null === $sprint->getId();
        $form = $this->createForm(SprintBoardWizard::class, $sprint);
        $form->handleRequest($request);

        $category = $form->isSubmitted() && $form->isValid() ? $sprint->getCategory() : null;
        if (null !== $category && $this->takeMembers($form, $sprint)) {
            // The address of the board follows its name
            $sprint->setIdentifier(
                $this->sprintBoardRepository->identifierFor((string) $sprint->getName(), $sprint->getId()),
            );
            $this->entityManager->persist($sprint);
            $this->entityManager->flush();

            $this->addFlash('success', $isNew ? 'Board created.' : 'Board edited.');

            return $this->redirectToRoute('sprint', [
                'category' => $category->getIdentifier(),
                'board' => $sprint->getIdentifier(),
            ]);
        }

        // The people of the field "People" with their names and avatars, see _board_form_access.html.twig
        $access = [
            'members' => $this->boardMembers->shown((string) $form->get(BoardAccessFields::MEMBERS)->getData()),
            'searchUrl' => $this->generateUrl('activity_people'),
            // The owners a board of reviews filters for, shown like the people
            'owners' => $form->has('reviewFilter')
                ? $this->boardMembers->shown((string) $form->get('reviewFilter')->get('owners')->getViewData())
                : [],
        ];

        return $this->render('sprint/wizard.html.twig', $wizardContext + $access + [
            'form' => $form,
            'sprint' => $sprint,
            'handWrittenQuery' => null,
            'droppedClauses' => [],
        ]);
    }

    /**
     * Shares the board with the people of the field "People", false with an error at the field if one of them has no
     * TYPO3 account Forger knows: the board is not saved then.
     *
     * @param FormInterface<SprintBoard> $form
     */
    private function takeMembers(FormInterface $form, SprintBoard $sprint): bool
    {
        $field = $form->get(BoardAccessFields::MEMBERS);
        $members = $this->boardMembers->resolve((string) $field->getData());
        if ([] !== $members['unknown']) {
            $field->addError(new FormError(sprintf(
                'Forger does not know the TYPO3 account of %s.',
                implode(', ', $members['unknown']),
            )));

            return false;
        }
        $sprint->setMembers($members['usernames'], new \DateTimeImmutable());

        return true;
    }

    /**
     * The filters a query written by hand comes closest to, and the parts of it they leave out. A query that cannot
     * be read leaves out everything.
     *
     * @return array{filter: BoardFilter, dropped: list<string>}
     */
    private function importHandWrittenQuery(SprintBoard $sprint): array
    {
        try {
            $query = Yaml::parse((string) $sprint->getQuery());
        } catch (ParseException) {
            $query = null;
        }
        if (!is_array($query)) {
            return ['filter' => new BoardFilter(), 'dropped' => [(string) $sprint->getQuery()]];
        }

        return BoardFilter::approximate($query);
    }

    /**
     * Renames a board from its title, see assets/ts/sprint/inline-rename.ts. Its identifier follows its name, so
     * the answer has the new address of the board.
     */
    #[Route(
        path: '/boards/rename/{uuid}',
        name: 'sprint_rename',
        requirements: ['uuid' => self::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_FORGER_MANAGEMENT')]
    public function renameSprint(Request $request, string $uuid): JsonResponse
    {
        $sprint = $this->sprintBoardRepository->findOneBy(['uuid' => $uuid]);
        if (null === $sprint) {
            return new JsonResponse(['error' => 'There is no such board.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid(self::RENAME_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            return new JsonResponse(['error' => 'The page is too old. Load it again.'], Response::HTTP_FORBIDDEN);
        }
        $name = trim($request->getPayload()->getString('name'));
        if ('' === $name || mb_strlen($name) > 200) {
            return new JsonResponse(
                ['error' => 'A name has 1 to 200 characters.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        $sprint->setName($name);
        $sprint->setIdentifier($this->sprintBoardRepository->identifierFor($name, $sprint->getId()));
        $this->entityManager->flush();

        return new JsonResponse(['name' => $name, 'url' => $this->generateUrl('sprint', [
            'category' => (string) $sprint->getCategory()?->getIdentifier(),
            'board' => $sprint->getIdentifier(),
        ])]);
    }

    #[Route(
        path: '/boards/delete/{uuid}',
        name: 'sprint_delete',
        requirements: ['uuid' => self::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_FORGER_MANAGEMENT')]
    public function deleteSprint(Request $request, string $uuid): Response
    {
        $sprint = $this->sprintBoardRepository->findOneBy(['uuid' => $uuid]);
        if (null === $sprint || !$this->isCsrfTokenValid(
            self::DELETE_TOKEN_ID,
            $request->getPayload()->getString('_token'),
        )) {
            return $this->redirectToRoute('sprint_overview');
        }
        $category = $sprint->getCategory()?->getIdentifier();
        $this->entityManager->remove($sprint);
        $this->entityManager->flush();
        $this->addFlash('success', 'Board deleted.');

        $anchor = null === $category ? [] : ['_fragment' => 'sprints-' . $category];

        return $this->redirectToRoute('sprint_overview', $anchor);
    }

    #[Route(
        path: '/boards/delete/{uuid}/confirm',
        name: 'sprint_delete_confirm',
        requirements: ['uuid' => self::UUID],
    )]
    #[IsGranted('ROLE_FORGER_MANAGEMENT')]
    public function deleteSprintConfirm(string $uuid): Response
    {
        $sprint = $this->sprintBoardRepository->findOneBy(['uuid' => $uuid]);
        if (null !== $sprint) {
            return $this->render('sprint/delete_confirm.html.twig', [
                'sprint' => $sprint,
            ]);
        }

        return $this->redirectToRoute('dashboard_index');
    }

    /**
     * A sprint board. Without a board, or with one of another category, the overview shows the boards of the
     * category instead. The cards come in the order the board sets, or in the one of the parameter "sort". A board
     * of issues shows its issues or the reviews of its issues, see boardParts().
     */
    #[Route(
        path: '/boards/{category}/{board}',
        name: 'sprint',
        requirements: ['board' => self::BOARD_SLUG],
        defaults: ['category' => 'index', 'board' => null],
    )]
    public function index(Request $request, string $category, ?string $board = null): Response
    {
        $sprintCategory = $this->sprintBoardCategoryRepository->findOneBy(['identifier' => $category]);
        if (null === $sprintCategory) {
            return new Response('Board not found', Response::HTTP_NOT_FOUND);
        }
        $board = $this->boardOf($sprintCategory, $board);
        if (null === $board) {
            return $this->redirectToRoute('sprint_overview', ['_fragment' => 'sprints-' . $category]);
        }

        return $this->render('sprint/index.html.twig', $this->boardParts($board, $request) + [
            'boardInfo' => (string) $board->getName(),
            'category' => $category,
            'categoryName' => (string) $sprintCategory->getName(),
            'uuid' => $board->getUuid(),
            'board' => (string) $board->getIdentifier(),
            'restricted' => $board->isRestricted(),
            'members' => $board->getMembers()->count(),
        ]);
    }

    /**
     * The parts of a sprint board that change with its issues or changes, as HTML: the board loads them again every
     * minute, see assets/ts/sprint/live-board.ts.
     */
    #[Route(
        path: '/boards/{category}/{board}/columns',
        name: 'sprint_columns',
        requirements: ['board' => self::BOARD_SLUG],
        methods: ['GET'],
    )]
    public function columns(Request $request, string $category, string $board): Response
    {
        $sprintCategory = $this->sprintBoardCategoryRepository->findOneBy(['identifier' => $category]);
        $board = null === $sprintCategory ? null : $this->boardOf($sprintCategory, $board);
        if (null === $board) {
            return new Response('Board not found', Response::HTTP_NOT_FOUND);
        }
        $parts = $this->boardParts($board, $request);
        $template = self::CHANGES === $parts['shows']
            ? 'sprint/_review_parts.html.twig'
            : 'sprint/_board_parts.html.twig';

        return $this->render($template, $parts);
    }

    /**
     * What the parts of a board show, in the order of "sort" or of the board: its issues, or its changes on a board
     * of reviews. A board of issues also has the view of its reviews: the open changes that name one of its issues,
     * in the columns of a board of reviews. It opens with the view it sets, or with the one of "view".
     *
     * @return array<string, mixed>
     */
    private function boardParts(SprintBoard $board, Request $request): array
    {
        $sort = $request->query->getString('sort');
        $type = $board->getType();
        // A board of issues opens with the view it sets, "view" asks for the other one
        $view = BoardTypeEnum::tryFrom($request->query->getString('view')) ?? $board->getFirstView();
        if (BoardTypeEnum::REVIEWS === $type || BoardTypeEnum::REVIEWS === $view) {
            $reviewSort = ReviewSortEnum::fromRequest($sort, false, $board->getReviewSort());
            $filter = $board->getReviewFilter();
            if (BoardTypeEnum::ISSUES === $type) {
                $filter = new ReviewBoardFilter();
                $filter->issueQuery = $board->getQuery();
            }

            return [
                'type' => $type,
                'shows' => self::CHANGES,
                'cards' => $this->reviewBoards->cards($filter, $reviewSort),
                'sort' => $reviewSort,
                'sortOptions' => ReviewSortEnum::options(false),
            ];
        }
        $issueSort = IssueSortEnum::fromRequest($sort, false, $board->getCardSort());
        $cards = $this->sprintBoards->cards($board, $issueSort);

        return [
            'type' => BoardTypeEnum::ISSUES,
            'shows' => self::ISSUES,
            'cards' => $cards,
            'progress' => SprintBoards::progress($cards),
            'sort' => $issueSort,
            'sortOptions' => IssueSortEnum::options(false),
        ];
    }

    /**
     * The board of the category by its identifier, null if there is none or the user may not see it.
     */
    private function boardOf(SprintBoardCategory $category, ?string $slug): ?SprintBoard
    {
        if (null === $slug) {
            return null;
        }
        $board = $this->sprintBoardRepository->findOneBy(['identifier' => $slug, 'category' => $category]);

        // A restricted board the user may not see is as missing as one that does not exist
        return null !== $board && $this->isGranted(SprintBoardVoter::VIEW, $board) ? $board : null;
    }
}
