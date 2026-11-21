<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Report\GetInvolved;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GetInvolvedController extends AbstractController
{
    public function __construct(
        private readonly GetInvolved $getInvolved,
    ) {
    }

    #[Route(path: '/get-involved', name: 'get_involved', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('get_involved/index.html.twig', [
            'smallReviews' => $this->getInvolved->smallReviews(),
            'goodFirstIssues' => $this->getInvolved->goodFirstIssues(),
            'regressions' => $this->getInvolved->regressions(),
            'newBugs' => $this->getInvolved->newBugs(),
        ]);
    }
}
