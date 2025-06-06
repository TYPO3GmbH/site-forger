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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class SprintCategoryController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly SprintBoardCategoryRepository $sprintBoardCategoryRepository)
    {
    }

    #[Route(path: '/sprintcategory', name: 'sprintcategory_index')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(): Response
    {
        return $this->render(
            'sprintcategory/index.html.twig',
            [
                'items' => $this->sprintBoardCategoryRepository->findAll(),
            ]
        );
    }

    #[Route(path: '/sprintcategory/create', name: 'sprintcategory_create')]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): Response
    {
        $category = new SprintBoardCategory();
        $form = $this->createForm(SprintBoardCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($category);
            $this->entityManager->flush();

            $this->addFlash('success', 'Sprint category created.');

            return $this->redirectToRoute('sprintcategory_index');
        }

        return $this->render('sprintcategory/form.html.twig', ['form' => $form]);
    }

    #[Route(path: '/sprintcategory/edit/{categoryId}', name: 'sprintcategory_edit')]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, int $categoryId): Response
    {
        $category = $this->sprintBoardCategoryRepository->find($categoryId);
        if (null === $category) {
            return $this->redirectToRoute('sprintcategory_create');
        }
        $form = $this->createForm(SprintBoardCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($category);
            $this->entityManager->flush();

            $this->addFlash('success', 'Sprint category updated.');

            return $this->redirectToRoute('sprintcategory_index');
        }

        return $this->render('sprintcategory/form.html.twig', ['form' => $form, 'edit' => true]);
    }

    #[Route(path: '/sprintcategory/delete/{categoryId}', name: 'sprintcategory_delete')]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $categoryId): Response
    {
        $category = $this->sprintBoardCategoryRepository->find($categoryId);
        if (null !== $category) {
            $this->entityManager->remove($category);
            $this->entityManager->flush();

            $this->addFlash('danger', 'Sprint category deleted.');
        }

        return $this->redirectToRoute('sprintcategory_index');
    }
}
