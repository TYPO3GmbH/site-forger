<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class StaticPageController extends AbstractController
{
    /**
     * @Route("/legal", name="static_legal")
     */
    public function legalAction(): Response
    {
        return $this->render('static_page/legal.html.twig');
    }
    /**
     * @Route("/privacy", name="static_privacy")
     */
    public function privacyAction(): Response
    {
        return $this->render('static_page/privacy.html.twig');
    }
}
