<?php

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
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CompanyController extends AbstractController
{
    private $companyRepository;

    public function __construct(CompanyRepository $companyRepository)
    {
        $this->companyRepository = $companyRepository;
    }

    /**
     * @Route("/management/companies", name="management_companies")
     * @IsGranted("ROLE_ADMIN")
     * @return JsonResponse
     */
    public function companies(): Response
    {
        return $this->render('management/company/list.html.twig', [
            'companies' => $this->companyRepository->findAll()
        ]);
    }

    /**
     * @Route("/management/companies/create", name="management_companies_create")
     * @IsGranted("ROLE_ADMIN")
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function createCompanyEntry(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $entry = new Company();
        $form = $this->createForm(\App\Form\Company::class, $entry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($entry);
            $entityManager->flush();

            $this->addFlash('success', 'Company "' . $entry->getName() . '" created.');

            return $this->redirectToRoute('management_companies');
        }

        return $this->render(
            'management/company/form.html.twig',
            [
                'form' => $form->createView(),
            ]
        );
    }

    /**
     * @Route("/management/company/delete/{id}", name="management_company_delete")
     * @IsGranted("ROLE_ADMIN")
     *
     * @param int $id
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function deleteCompany(int $id, EntityManagerInterface $entityManager): Response
    {
        $company = $this->companyRepository->findOneBy(['id' => $id]);

        if ($company !== null) {
            $entityManager->remove($company);
            $entityManager->flush();
            $this->addFlash('success', 'Company "' . $company->getName() . '" deleted.');
        }

        return $this->redirectToRoute('management_companies');
    }
}
