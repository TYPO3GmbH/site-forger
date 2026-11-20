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
use App\Form\SprintBoardWizard;
use App\Repository\SprintBoardCategoryRepository;
use App\Repository\SprintBoardRepository;
use App\Sprint\BoardCount;
use App\Sprint\BoardFilter;
use App\Sprint\ReviewBoards;
use App\Sprint\SprintBoards;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class SprintController extends AbstractController
{
    public function __construct(
        private readonly SprintBoards $sprintBoards,
        private readonly ReviewBoards $reviewBoards,
        private readonly SprintBoardCategoryRepository $sprintBoardCategoryRepository,
        private readonly SprintBoardRepository $sprintBoardRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/sprint/list', name: 'sprint_list')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function list(): Response
    {
        return $this->render('management/sprint/index.html.twig', [
            'items' => $this->sprintBoardRepository->findAll(),
        ]);
    }

    /**
     * All sprint boards by category, each with how far its issues have got.
     */
    #[Route(path: '/sprint', name: 'sprint_overview', priority: 1)]
    public function overview(): Response
    {
        $categories = [];
        foreach ($this->sprintBoardCategoryRepository->findBy([], ['sorting' => 'ASC', 'name' => 'ASC']) as $category) {
            $boards = [];
            foreach ($this->sprintBoardRepository->findBy(['category' => $category], ['sorting' => 'ASC', 'name' => 'ASC']) as $board) {
                try {
                    $count = $this->sprintBoards->counts((string) $board->getQuery());
                } catch (ParseException) {
                    $count = null;
                }
                $boards[] = ['board' => $board, 'count' => $count];
            }
            $categories[] = ['category' => $category, 'boards' => $boards];
        }

        return $this->render('sprint/overview.html.twig', [
            'categories' => $categories,
        ]);
    }

    #[Route(path: '/sprint/reviews/{reviewType}', name: 'sprint_reviews', defaults: ['reviewType' => 'bugfix'])]
    public function reviewSprintAction(string $reviewType): Response
    {
        return $this->render('sprint/review.html.twig', [
            'menu' => $this->reviewBoards->menu($reviewType),
            'cards' => $this->reviewBoards->cards($reviewType),
        ]);
    }

    #[Route(path: '/sprint/create', name: 'sprint_create')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function createSprint(Request $request): Response
    {
        return $this->handleBoardForm($request, $this->newBoard($request), SprintBoardWizard::class);
    }

    #[Route(path: '/sprint/create/yaml', name: 'sprint_create_yaml')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function createSprintAsYaml(Request $request): Response
    {
        return $this->handleBoardForm($request, $this->newBoard($request), \App\Form\SprintBoard::class);
    }

    /**
     * A new board, in the category the link it was created from names (?category=identifier).
     */
    private function newBoard(Request $request): SprintBoard
    {
        $board = new SprintBoard();
        $identifier = $request->query->getString('category');
        if ('' !== $identifier) {
            $board->setCategory($this->sprintBoardCategoryRepository->findOneBy(['identifier' => $identifier]));
        }

        return $board;
    }

    #[Route(path: '/sprint/edit/{sprintId}', name: 'sprint_edit')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function editSprint(Request $request, int $sprintId): Response
    {
        $sprint = $this->sprintBoardRepository->find($sprintId);
        if (null === $sprint) {
            return $this->redirectToRoute('sprint_create');
        }

        if (null !== BoardFilter::fromYaml($sprint->getQuery())) {
            return $this->handleBoardForm($request, $sprint, SprintBoardWizard::class);
        }

        // A query written by hand opens in the wizard with the filters it comes closest to, saving replaces it
        $import = $this->importHandWrittenQuery($sprint);
        if (null === $import) {
            return $this->handleBoardForm($request, $sprint, \App\Form\SprintBoard::class);
        }
        $handWrittenQuery = (string) $sprint->getQuery();
        $sprint->setQuery($import['filter']->toYaml());

        return $this->handleBoardForm($request, $sprint, SprintBoardWizard::class, [
            'handWrittenQuery' => $handWrittenQuery,
            'droppedClauses' => $import['dropped'],
        ]);
    }

    #[Route(path: '/sprint/edit/{sprintId}/yaml', name: 'sprint_edit_yaml')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function editSprintAsYaml(Request $request, int $sprintId): Response
    {
        $sprint = $this->sprintBoardRepository->find($sprintId);
        if (null === $sprint) {
            return $this->redirectToRoute('sprint_create_yaml');
        }

        return $this->handleBoardForm($request, $sprint, \App\Form\SprintBoard::class);
    }

    /**
     * The issues the filters of the wizard currently select, grouped like on the board.
     */
    #[Route(path: '/sprint/wizard/preview', name: 'sprint_wizard_preview', methods: ['POST'])]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function previewSprint(Request $request): Response
    {
        $form = $this->createForm(SprintBoardWizard::class, new SprintBoard());
        $form->handleRequest($request);
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
     * @param class-string<SprintBoardWizard|\App\Form\SprintBoard> $formType
     * @param array<string, mixed>                                  $wizardContext
     */
    private function handleBoardForm(Request $request, SprintBoard $sprint, string $formType, array $wizardContext = []): Response
    {
        $isNew = null === $sprint->getId();
        $form = $this->createForm($formType, $sprint);
        $form->handleRequest($request);

        $category = $form->isSubmitted() && $form->isValid() ? $sprint->getCategory() : null;
        if (null !== $category) {
            $this->entityManager->persist($sprint);
            $this->entityManager->flush();

            $this->addFlash('success', $isNew ? 'Sprint board created.' : 'Sprint board edited.');

            return $this->redirectToRoute('sprint', [
                'category' => $category->getIdentifier(),
                'sprintId' => $sprint->getId(),
            ]);
        }

        if (SprintBoardWizard::class === $formType) {
            return $this->render('sprint/wizard.html.twig', $wizardContext + [
                'form' => $form,
                'sprint' => $sprint,
                'handWrittenQuery' => null,
                'droppedClauses' => [],
            ]);
        }

        return $this->render('sprint/form.html.twig', [
            'form' => $form,
            'sprint' => $sprint,
            'edit' => !$isNew,
            'wizardAvailable' => $isNew || null !== BoardFilter::fromYaml($sprint->getQuery()) || null !== $this->importHandWrittenQuery($sprint),
        ]);
    }

    /**
     * The filters of the wizard a query written by hand comes closest to, null if it has none of them.
     *
     * @return array{filter: BoardFilter, dropped: list<string>}|null
     */
    private function importHandWrittenQuery(SprintBoard $sprint): ?array
    {
        try {
            $query = Yaml::parse((string) $sprint->getQuery());
        } catch (ParseException) {
            return null;
        }
        $import = is_array($query) ? BoardFilter::approximate($query) : null;

        return null !== $import && $import['filter']->hasFilter() ? $import : null;
    }

    #[Route(path: '/sprint/delete/{sprintId}', name: 'sprint_delete')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function deleteSprint(int $sprintId): Response
    {
        $sprint = $this->sprintBoardRepository->find($sprintId);
        if (null !== $sprint) {
            $this->entityManager->remove($sprint);
            $this->entityManager->flush();
            $this->addFlash('success', 'Sprint deleted.');
        }

        return $this->redirectToRoute('sprint_list');
    }

    #[Route(path: '/sprint/delete/{sprintId}/confirm', name: 'sprint_delete_confirm')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function deleteSprintConfirm(int $sprintId): Response
    {
        $sprint = $this->sprintBoardRepository->find($sprintId);
        if (null !== $sprint) {
            return $this->render('sprint/delete_confirm.html.twig', [
                'sprint' => $sprint,
            ]);
        }

        return $this->redirectToRoute('dashboard_index');
    }

    #[Route(path: '/sprint/{category}/{sprintId}', name: 'sprint', defaults: ['category' => 'index'])]
    public function index(string $category, ?int $sprintId = null): Response
    {
        $sprintCategory = $this->sprintBoardCategoryRepository->findOneBy(['identifier' => $category]);
        if (null === $sprintCategory) {
            return new Response('Sprint board not found', Response::HTTP_NOT_FOUND);
        }
        $boards = $this->sprintBoardRepository->findBy(['category' => $sprintCategory], ['sorting' => 'ASC']);
        $activeId = $sprintId ?? 0;
        $active = array_find($boards, static fn (SprintBoard $board): bool => $board->getId() === $activeId);
        $cards = null === $active ? [] : $this->sprintBoards->cards($active);

        return $this->render('sprint/index.html.twig', [
            'menu' => $this->menu($boards, $activeId),
            'cards' => $cards,
            'boardInfo' => $active?->getName() ?? [],
            'progress' => null === $active ? [] : SprintBoards::progress(array_map(count(...), $cards)),
            'category' => $category,
            'categoryName' => (string) $sprintCategory->getName(),
            'sprintId' => $activeId,
        ]);
    }

    /**
     * @param list<SprintBoard> $boards
     *
     * @return list<array{id: int|null, title: string|null, active: bool, count: BoardCount}>
     */
    private function menu(array $boards, int $activeId): array
    {
        return array_map(fn (SprintBoard $board): array => [
            'id' => $board->getId(),
            'title' => $board->getName(),
            'active' => $activeId === $board->getId(),
            'count' => $this->sprintBoards->counts((string) $board->getQuery()),
        ], $boards);
    }
}
