<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class SprintController extends AbstractController
{
    /**
     * @Route("/sprint", name="sprint")
     */
    public function index()
    {
        return $this->render('sprint/index.html.twig', [
            'controller_name' => 'SprintController',
        ]);
    }
}
