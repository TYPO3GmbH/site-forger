<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller\Management;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CompanyController extends AbstractController
{
    public function __construct(private readonly CompanyRepository $companyRepository)
    {
    }

    #[Route(path: '/companies/management', name: 'management_companies')]
    public function companies(): Response
    {
        return $this->render('management/company/list.html.twig', [
            'companies' => $this->companyRepository->findAll(),
        ]);
    }

    #[Route(path: '/companies/management/manage/{id}', name: 'management_companies_manage')]
    public function manageCompanyEntry(Request $request, EntityManagerInterface $entityManager, int $id = 0): Response
    {
        if (0 === $id) {
            $entry = new Company();
        } else {
            $entry = $this->companyRepository->find($id);

            if (null === $entry) {
                $this->addFlash('danger', 'Invalid company requested.');

                return $this->redirectToRoute('management_companies');
            }
        }
        $form = $this->createForm(\App\Form\Company::class, $entry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($entry);
            $entityManager->flush();

            $this->addFlash('success', 'Company "' . $entry->getName() . '" saved.');

            return $this->redirectToRoute('management_companies');
        }

        return $this->render(
            'management/company/form.html.twig',
            [
                'edit' => null !== $entry->getId(),
                'form' => $form,
            ]
        );
    }

    #[Route(path: '/companies/management/delete/{id}', name: 'management_company_delete')]
    public function deleteCompany(int $id, EntityManagerInterface $entityManager): Response
    {
        $company = $this->companyRepository->findOneBy(['id' => $id]);

        if (null !== $company) {
            $entityManager->remove($company);
            $entityManager->flush();
            $this->addFlash('success', 'Company "' . $company->getName() . '" deleted.');
        }

        return $this->redirectToRoute('management_companies');
    }
}
