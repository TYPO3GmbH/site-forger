<?php

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
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SprintCategoryController extends AbstractController
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;
    /**
     * @var SprintBoardCategoryRepository
     */
    private $sprintBoardCategoryRepository;

    public function __construct(EntityManagerInterface $entityManager, SprintBoardCategoryRepository $sprintBoardCategoryRepository)
    {
        $this->entityManager = $entityManager;
        $this->sprintBoardCategoryRepository = $sprintBoardCategoryRepository;
    }

    /**
     * @Route("/sprintcategory", name="sprintcategory_index")
     * @IsGranted("ROLE_ADMIN")
     *
     * @return Response
     */
    public function index(): Response
    {
        return $this->render(
            'sprintcategory/index.html.twig',
            [
                'items' => $this->sprintBoardCategoryRepository->findAll(),
            ]
        );
    }

    /**
     * @Route("/sprintcategory/create", name="sprintcategory_create")
     * @IsGranted("ROLE_ADMIN")
     *
     * @param Request $request
     *
     * @return Response
     */
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

        return $this->render('sprintcategory/form.html.twig', ['form' => $form->createView()]);
    }

    /**
     * @Route("/sprintcategory/edit/{categoryId}", name="sprintcategory_edit")
     * @IsGranted("ROLE_ADMIN")
     *
     * @param Request $request
     * @param int     $categoryId
     *
     * @return Response
     */
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

        return $this->render('sprintcategory/form.html.twig', ['form' => $form->createView(), 'edit' => true]);
    }

    /**
     * @Route("/sprintcategory/delete/{categoryId}", name="sprintcategory_delete")
     * @IsGranted("ROLE_ADMIN")
     *
     * @param Request $request
     * @param int     $categoryId
     *
     * @return Response
     */
    public function delete(Request $request, int $categoryId): Response
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
