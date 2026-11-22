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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The activity stream: what happens on Forge and in Gerrit, as a list in the order of time. The page is a frame: the
 * element <forger-activity> renders it with the API, see assets/ts/activity/ and ApiV1Controller::activity(). The
 * filter is in the address, so a view can be shared. The statistics of the same events are on the page of
 * DashboardController::statistics().
 */
#[Route(path: '/activity', name: 'activity_')]
class StreamController extends AbstractController
{
    /**
     * The events the dashboard shows.
     */
    public const int DASHBOARD_COUNT = 6;

    #[Route(name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('stream/page.html.twig', ['view' => 'stream']);
    }

    /**
     * The statistics had their place here once: shared links keep their filter.
     */
    #[Route(path: '/statistics', name: 'statistics', methods: ['GET'])]
    public function statistics(Request $request): RedirectResponse
    {
        $query = $request->getQueryString();
        $url = $this->generateUrl('dashboard_statistics') . (null === $query ? '' : '?' . $query);

        return $this->redirect($url, Response::HTTP_MOVED_PERMANENTLY);
    }
}
