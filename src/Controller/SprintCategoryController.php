<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\SprintBoardCategory;
use App\Form\SprintBoardCategoryType;
use App\Repository\SprintBoardCategoryRepository;
use App\Service\IconCatalog;
use App\Sprint\CategoryOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The categories of the sprint boards: add, change, move and remove them. The sprint overview lists them with their
 * boards and links here, see templates/sprint/overview.html.twig.
 */
#[IsGranted('ROLE_FORGER_MANAGEMENT')]
class SprintCategoryController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'sprint-category';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SprintBoardCategoryRepository $sprintBoardCategoryRepository,
        private readonly IconCatalog $icons,
    ) {
    }

    #[Route(path: '/board-category/create', name: 'sprintcategory_create')]
    public function create(Request $request): Response
    {
        return $this->handleForm($request, new SprintBoardCategory(), 'Category created.');
    }

    #[Route(path: '/board-category/edit/{categoryId}', name: 'sprintcategory_edit')]
    public function edit(Request $request, int $categoryId): Response
    {
        $category = $this->sprintBoardCategoryRepository->find($categoryId);
        if (null === $category) {
            throw $this->createNotFoundException('There is no such category.');
        }

        return $this->handleForm($request, $category, 'Category updated.');
    }

    /**
     * Removes a category without boards. A category with boards stays: its boards would go with it.
     */
    #[Route(path: '/board-category/delete/{categoryId}', name: 'sprintcategory_delete', methods: ['POST'])]
    public function delete(Request $request, int $categoryId): Response
    {
        $category = $this->sprintBoardCategoryRepository->find($categoryId);
        if (null === $category || !$this->isCsrfTokenValid(
            self::CSRF_TOKEN_ID,
            $request->getPayload()->getString('_token'),
        )) {
            return $this->redirectToRoute('sprint_overview');
        }
        if (!$category->getSprintBoards()->isEmpty()) {
            $this->addFlash('danger', 'Move or delete the boards of the category first.');

            return $this->redirectToRoute('sprint_overview', ['_fragment' => 'sprints-' . $category->getIdentifier()]);
        }
        $this->entityManager->remove($category);
        $this->entityManager->flush();
        $this->addFlash('success', 'Category deleted.');

        return $this->redirectToRoute('sprint_overview');
    }

    /**
     * Moves a category one place up or down on the sprint overview, see CategoryOrder.
     */
    #[Route(
        path: '/board-category/move/{categoryId}/{direction}',
        name: 'sprintcategory_move',
        requirements: ['direction' => CategoryOrder::UP . '|' . CategoryOrder::DOWN],
        methods: ['POST'],
    )]
    public function move(Request $request, int $categoryId, string $direction): Response
    {
        $category = $this->sprintBoardCategoryRepository->find($categoryId);
        if (null === $category || !$this->isCsrfTokenValid(
            self::CSRF_TOKEN_ID,
            $request->getPayload()->getString('_token'),
        )) {
            return $this->redirectToRoute('sprint_overview');
        }
        CategoryOrder::move($this->sprintBoardCategoryRepository->findAll(), $category, $direction);
        $this->entityManager->flush();

        return $this->redirectToRoute('sprint_overview', ['_fragment' => 'sprints-' . $category->getIdentifier()]);
    }

    private function handleForm(Request $request, SprintBoardCategory $category, string $message): Response
    {
        $form = $this->createForm(SprintBoardCategoryType::class, $category);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($category);
            $this->entityManager->flush();
            $this->addFlash('success', $message);

            return $this->redirectToRoute('sprint_overview', ['_fragment' => 'sprints-' . $category->getIdentifier()]);
        }

        return $this->render('sprintcategory/form.html.twig', [
            'form' => $form,
            'edit' => null !== $category->getId(),
            'icons' => $this->icons->identifiers(),
        ]);
    }
}
