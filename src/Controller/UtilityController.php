<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\BackportBlacklistEntry;
use App\Report\Backport\BackportList;
use App\Repository\BackportBlacklistEntryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/utilities', name: 'utilities_')]
class UtilityController extends AbstractController
{
    public function __construct(
        private readonly BackportList $backportList,
        private readonly BackportBlacklistEntryRepository $backportBlacklistEntryRepository
    ) {
    }

    /**
     * The overview of the tools: the items of the menu "Tools", see MenuBuilder and templates/utility/index.html.twig.
     */
    #[Route(path: '', name: 'index')]
    public function index(): Response
    {
        return $this->render('utility/index.html.twig');
    }

    /**
     * The changes merged into main whose backports are missing or wait for their review, see BackportList.
     */
    #[Route(path: '/backport', name: 'backport')]
    public function backport(): Response
    {
        $rows = $this->backportList->rows();

        return $this->render('utility/backport.html.twig', [
            'rows' => $rows ?? new ArrayCollection(),
            'fileExists' => null !== $rows,
        ]);
    }

    #[Route(path: '/backport/create', name: 'backport_create')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
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

            $this->addFlash('success', 'The change is excluded from backports.');

            return $this->redirectToRoute('utilities_backport_index');
        }

        if ($request->query->has('changeId')) {
            $form->get('changeId')->setData($request->query->getString('changeId'));
        }

        return $this->render(
            'utility/backport_form.html.twig',
            [
                'form' => $form,
            ]
        );
    }

    #[Route(path: '/backport/quickcreate/{changeId}', name: 'backport_quickcreate')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function quickCreateBackportBlacklistEntry(
        string $changeId,
        EntityManagerInterface $entityManager
    ): Response {
        $entry = new BackportBlacklistEntry()
            ->setChangeId($changeId)
            ->setComment('');
        $entityManager->persist($entry);
        $entityManager->flush();
        $this->addFlash('success', 'The change is excluded from backports.');

        return $this->redirectToRoute('utilities_backport');
    }

    #[Route(path: '/backport/edit/{entryId}', name: 'backport_edit')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function editBackportBlacklistEntry(
        Request $request,
        int $entryId,
        EntityManagerInterface $entityManager
    ): Response {
        $entry = $this->backportBlacklistEntryRepository->find($entryId);
        $form = $this->createForm(\App\Form\BackPortBlacklistEntry::class, $entry);

        if (null === $entry) {
            $this->redirectToRoute('utilities_backport_create');
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($entry);
            $entityManager->flush();

            $this->addFlash('success', 'The backport exclusion is updated.');

            return $this->redirectToRoute('dashboard_index');
        }

        return $this->render(
            'utility/backport_form.html.twig',
            [
                'form' => $form,
                'edit' => true,
            ]
        );
    }

    #[Route(path: '/backport/delete/{changeId}', name: 'backport_delete')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function deleteBackportBlacklistEntry(
        string $changeId,
        EntityManagerInterface $entityManager
    ): Response {
        $backport = $this->backportBlacklistEntryRepository->findOneBy(['changeId' => $changeId]);

        if (null !== $backport) {
            $entityManager->remove($backport);
            $entityManager->flush();
            $this->addFlash('success', 'The backport exclusion is removed.');
        }

        return $this->redirectToRoute('utilities_backport_index');
    }

    #[Route(path: '/backport/list', name: 'backport_index')]
    #[IsGranted('ROLE_CORE_COMPONENT')]
    public function blacklistIndex(): Response
    {
        $entries = $this->backportBlacklistEntryRepository->findAll();

        return $this->render('utility/backport_blacklist.html.twig', ['items' => $entries]);
    }

    #[Route(path: '/rst', name: 'rst')]
    public function rst(): Response
    {
        $tags = [
            'Backend',
            'CLI',
            'Database',
            'FAL',
            'FlexForm',
            'Fluid',
            'Frontend',
            'LocalConfiguration',
            'JavaScript',
            'PHP-API',
            'RTE',
            'TCA',
            'TSConfig',
            'TypoScript',
            'YAML',
        ];

        // System extensions of the main branch
        $extensionKeys = [
            'adminpanel',
            'backend',
            'belog',
            'beuser',
            'core',
            'dashboard',
            'extbase',
            'extensionmanager',
            'felogin',
            'filelist',
            'filemetadata',
            'fluid',
            'fluid_styled_content',
            'form',
            'frontend',
            'impexp',
            'indexed_search',
            'info',
            'install',
            'linkvalidator',
            'lowlevel',
            'opendocs',
            'reactions',
            'recycler',
            'redirects',
            'reports',
            'rte_ckeditor',
            'scheduler',
            'seo',
            'styleguide',
            'sys_note',
            'theme_camino',
            'tstemplate',
            'viewpage',
            'webhooks',
            'workspaces',
        ];

        // Directories of typo3/sysext/core/Documentation/Changelog/ a ReST file goes into, see Changelog/Howto.rst of the core
        $changelogTargets = [
            'main' => ['label' => 'Only main', 'directories' => ['15.0']],
            'lts' => ['label' => 'Backported to 14.3', 'directories' => ['14.3.x']],
            'old-lts' => ['label' => 'Backported to 14.3 and 13.4', 'directories' => ['14.3.x', '13.4.x']],
        ];

        $viewData = [
            'tags' => $tags,
            'extensionKeys' => $extensionKeys,
            'changelogTargets' => $changelogTargets,
        ];

        return $this->render('utility/rst.html.twig', $viewData);
    }
}
