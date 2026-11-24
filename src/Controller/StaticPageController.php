<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StaticPageController extends AbstractController
{
    #[Route(path: '/help', name: 'static_help')]
    public function helpAction(): Response
    {
        return $this->render('static_page/help.html.twig');
    }

    /**
     * The guide for language models and agents, as https://llmstxt.org proposes it.
     */
    #[Route(path: '/llms.txt', name: 'static_llms_txt', methods: ['GET'], stateless: true)]
    public function llmsTxtAction(): Response
    {
        $response = $this->render('static_page/llms.txt.twig');
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
