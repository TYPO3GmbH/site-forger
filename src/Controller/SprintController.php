<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

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
