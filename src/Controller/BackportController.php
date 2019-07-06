<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class BackportController extends AbstractController
{
    /**
     * @Route("/backport", name="backport")
     */
    public function index()
    {
        return $this->render('backport/index.html.twig', [
            'controller_name' => 'BackportController',
        ]);
    }
}
