<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\BackportBlacklistEntry;
use App\Repository\BackportBlacklistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BackPortBlacklistEntryController extends AbstractController
{
    /**
     * @var BackportBlacklistEntryRepository
     */
    private $backportBlacklistEntryRepository;

    public function __construct(
        BackportBlacklistEntryRepository $backportBlacklistEntryRepository
    ) {
        $this->backportBlacklistEntryRepository = $backportBlacklistEntryRepository;
    }

    /**
     * @Route("/utilities/backport/create", name="backport_create")
     * @IsGranted("ROLE_ADMIN")
     *
     * @param Request $request
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function createBackportBlacklistEntry(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $entry = new BackportBlacklistEntry();
        $form = $this->createForm(\App\Form\BackPortBlacklistEntry::class, $entry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($entry);
            $entityManager->flush();

            $this->addFlash('success', 'Blacklist entry created.');

            return $this->redirectToRoute('dashboard_index');
        }

        return $this->render(
            'utility/backport_form.html.twig',
            [
                'form' => $form->createView(),
            ]
        );
    }

    /**
     * @Route("/utilities/backport/edit/{entryId}", name="backport_edit")
     * @IsGranted("ROLE_ADMIN")
     *
     * @param Request $request
     * @param int $entryId
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function editBackportBlacklistEntry(
        Request $request,
        int $entryId,
        EntityManagerInterface $entityManager
    ): Response {
        $entry = $this->backportBlacklistEntryRepository->find($entryId);
        $form = $this->createForm(\App\Form\BackPortBlacklistEntry::class, $entry);

        if (null === $entry) {
            $this->redirectToRoute('backport_create');
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($entry);
            $entityManager->flush();

            $this->addFlash('success', 'Blacklist entry updated.');

            return $this->redirectToRoute('dashboard_index');
        }

        return $this->render(
            'utility/backport_form.html.twig',
            [
                'form' => $form->createView(),
                'edit' => true,
            ]
        );
    }
}
