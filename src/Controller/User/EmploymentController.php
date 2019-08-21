<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller\User;

use App\Entity\EmploymentTime;
use App\Repository\EmploymentTimeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * @IsGranted("IS_AUTHENTICATED_FULLY")
 */
class EmploymentController extends AbstractController
{
    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var EmploymentTimeRepository
     */
    private $employmentTimeRepository;

    public function __construct(TokenStorageInterface $tokenStorage, EmploymentTimeRepository $employmentTimeRepository)
    {
        $this->tokenStorage = $tokenStorage;
        $this->employmentTimeRepository = $employmentTimeRepository;
    }

    /**
     * @Route("/user/employment", name="user_employment")
     * @return Response
     */
    public function companyEmployment(): Response
    {
        $employmentTimes = $this->employmentTimeRepository->findByUsername($this->tokenStorage->getToken()->getUsername());
        return $this->render('user/employment/list.html.twig', [
            'employmentTimes' => $employmentTimes,
        ]);
    }

    /**
     * @Route("/user/employment/manage/{id}", name="user_employment_manage")
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @param int $id
     * @return Response
     */
    public function manageCompanyEmployment(Request $request, EntityManagerInterface $entityManager, int $id = 0): Response
    {
        if ($id === 0) {
            $entry = new EmploymentTime();
            $entry->setAccount($this->tokenStorage->getToken()->getUsername());
        } else {
            $entry = $this->employmentTimeRepository->findOneBy(['id' => $id, 'account' => $this->tokenStorage->getToken()->getUsername()]);

            if ($entry === null) {
                $this->addFlash('danger', 'Invalid employment given or not permitted.');
                return $this->redirectToRoute('user_employment');
            }
        }

        $form = $this->createForm(\App\Form\EmploymentTime::class, $entry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($entry);
            $entityManager->flush();

            $this->addFlash('success', 'Employment for company "' . $entry->getCompany()->getName() . '" saved.');
            return $this->redirectToRoute('user_employment');
        }

        return $this->render(
            'user/employment/form.html.twig',
            [
                'edit' => $entry->getId() !== null,
                'form' => $form->createView(),
            ]
        );
    }

    /**
     * @Route("/user/employment/delete/{id}", name="user_employment_delete")
     * @IsGranted("ROLE_ADMIN")
     *
     * @param int $id
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function deleteCompanyEmployment(int $id, EntityManagerInterface $entityManager): Response
    {
        $employment = $this->employmentTimeRepository->findOneBy(['id' => $id, 'account' => $this->tokenStorage->getToken()->getUsername()]);

        if ($employment !== null) {
            $entityManager->remove($employment);
            $entityManager->flush();
            $this->addFlash('success', 'Employment for company "' . $employment->getCompany()->getName() . '" deleted.');
        }

        return $this->redirectToRoute('user_employment');
    }
}
