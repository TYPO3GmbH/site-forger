<?php

declare(strict_types=1);

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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class EmploymentController extends AbstractController
{
    public function __construct(private readonly TokenStorageInterface $tokenStorage, private readonly EmploymentTimeRepository $employmentTimeRepository)
    {
    }

    #[Route(path: '/user/employment', name: 'user_employment')]
    public function companyEmployment(): Response
    {
        $employmentTimes = $this->employmentTimeRepository->findByUsername($this->tokenStorage->getToken()->getUserIdentifier());

        return $this->render('user/employment/list.html.twig', [
            'employmentTimes' => $employmentTimes,
        ]);
    }

    #[Route(path: '/user/employment/manage/{id}', name: 'user_employment_manage')]
    public function manageCompanyEmployment(Request $request, EntityManagerInterface $entityManager, int $id = 0): Response
    {
        if (0 === $id) {
            $entry = new EmploymentTime();
            $entry->setAccount($this->tokenStorage->getToken()->getUserIdentifier());
        } else {
            $entry = $this->employmentTimeRepository->findOneBy(['id' => $id, 'account' => $this->tokenStorage->getToken()->getUserIdentifier()]);

            if (null === $entry) {
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
                'edit' => null === $entry->getId(),
                'form' => $form,
            ]
        );
    }

    #[Route(path: '/user/employment/delete/{id}', name: 'user_employment_delete')]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteCompanyEmployment(int $id, EntityManagerInterface $entityManager): Response
    {
        $employment = $this->employmentTimeRepository->findOneBy(['id' => $id, 'account' => $this->tokenStorage->getToken()->getUserIdentifier()]);

        if (null !== $employment) {
            $entityManager->remove($employment);
            $entityManager->flush();
            $this->addFlash('success', 'Employment for company "' . $employment->getCompany()->getName() . '" deleted.');
        }

        return $this->redirectToRoute('user_employment');
    }
}
