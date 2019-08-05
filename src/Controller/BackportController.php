<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BackportController extends AbstractController
{
    /**
     * @var ParameterBagInterface
     */
    protected $parameters;

    public function __construct(ParameterBagInterface $parameters)
    {
        $this->parameters = $parameters;
    }

    /**
     * @Route("/backport", name="backport")
     */
    public function index(): Response
    {
        $viewData = [];
        $finalFilePath = $this->parameters->get('kernel.project_dir') . '/var/backports.json';
        if (is_file($finalFilePath)) {
            $backports = json_decode(file_get_contents($finalFilePath), true);
            $viewData['backports'] = $backports;
            $viewData['sum'] = count($backports);
        }
        return $this->render('backport/index.html.twig', $viewData);
    }
}
