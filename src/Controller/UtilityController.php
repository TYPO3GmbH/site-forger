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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/utilities", name="utilities_")
 */
class UtilityController extends AbstractController
{
    /**
     * @var ParameterBagInterface
     */
    protected $parameters;

    /**
     * @var BackportBlacklistEntryRepository
     */
    private $backportBlacklistEntryRepository;

    public function __construct(ParameterBagInterface $parameters, BackportBlacklistEntryRepository $backportBlacklistEntryRepository)
    {
        $this->parameters = $parameters;
        $this->backportBlacklistEntryRepository = $backportBlacklistEntryRepository;
    }

    /**
     * @Route("/backport", name="backport")
     */
    public function backport(): Response
    {
        $viewData = [];
        $viewData['backports'] = [];
        $viewData['sum'] = 0;
        $finalFilePath = $this->parameters->get('kernel.project_dir') . '/var/backports.json';
        $entries = $this->backportBlacklistEntryRepository->findAll();
        if (is_file($finalFilePath)) {
            $backports = json_decode(file_get_contents($finalFilePath), true);
            $viewData['backports'] = $backports;
            foreach ($viewData['backports'] as $key => $value) {
                $blacklist = $this->backportBlacklistEntryRepository->findOneBy(['changeId' => $key]);
                if (null !== $blacklist) {
                    unset($viewData['backports'][$key]);
                }
            }
            $viewData['sum'] = count($backports);
        }
        return $this->render('utility/backport.html.twig', $viewData);
    }

    /**
     * @Route("/backport/create", name="backport_create")
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

        if (null !== $request->get('changeId')) {
            $form->get('changeId')->setData($request->get('changeId'));
        }

        return $this->render(
            'utility/backport_form.html.twig',
            [
                'form' => $form->createView(),
            ]
        );
    }

    /**
     * @Route("/backport/quickcreate/{changeId}", name="backport_quickcreate")
     * @IsGranted("ROLE_ADMIN")
     *
     * @param Request $request
     * @param string $changeId
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function quickCreateBackportBlacklistEntry(
        Request $request,
        string $changeId,
        EntityManagerInterface $entityManager
    ): Response {
        $entry = (new BackportBlacklistEntry())
            ->setChangeId($changeId)
            ->setComment('');
        $entityManager->persist($entry);
        $entityManager->flush();
        $this->addFlash('success', 'Blacklist entry created.');

        return $this->redirectToRoute('utilities_backport');
    }

    /**
     * @Route("/backport/edit/{entryId}", name="backport_edit")
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

    /**
     * @Route("/backport/delete/{changeId}", name="backport_delete")
     * @IsGranted("ROLE_ADMIN")
     *
     * @param Request $request
     * @param string $changeId
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    public function deleteBackportBlacklistEntry(
        Request $request,
        string $changeId,
        EntityManagerInterface $entityManager
    ): Response {
        $backport = $this->backportBlacklistEntryRepository->findOneBy(['changeId' => $changeId]);

        if (null !== $backport) {
            $entityManager->remove($backport);
            $entityManager->flush();
            $this->addFlash('success', 'Backport deleted.');
        }

        return $this->redirectToRoute('utilities_backport_index');
    }

    /**
     * @Route("/backport/list", name="backport_index")
     * @IsGranted("ROLE_ADMIN")
     *
     * @return Response
     */
    public function blacklistIndex(

    ): Response {
        $entries = $this->backportBlacklistEntryRepository->findAll();

        return $this->render('utility/backport_blacklist.html.twig', ['items' => $entries]);
    }

    /**
     * @Route("/rst", name="rst")
     */
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
        ];

        $extensionKeys = [
            'about',
            'adminpanel',
            'backend',
            'belog',
            'beuser',
            'core',
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
            'recordlist',
            'recycler',
            'redirects',
            'reports',
            'rte_ckeditor',
            'scheduler',
            'seo',
            'setup',
            'sys_note',
            't3editor',
            'tstemplate',
            'viewpage',
            'workspaces',
        ];

        $viewData = [
            'tags' => $tags,
            'extensionKeys' => $extensionKeys,
        ];
        return $this->render('utility/rst.html.twig', $viewData);
    }

    /**
     * @Route("/", name="index")
     */
    public function index()
    {
        return $this->render('utility/index.html.twig', [
            'controller_name' => 'UtilityController',
        ]);
    }
}
