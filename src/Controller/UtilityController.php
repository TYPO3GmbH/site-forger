<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class UtilityController extends AbstractController
{
    /**
     * @Route("/utility", name="utility")
     */
    public function index()
    {
        return $this->render('utility/index.html.twig', [
            'controller_name' => 'UtilityController',
        ]);
    }
}
