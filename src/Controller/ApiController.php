<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Report\KeyFigures;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class ApiController extends AbstractController
{
    public function __construct(
        private readonly KeyFigures $keyFigures,
    ) {
    }

    #[Route(path: '/quick_stats', name: 'quick_stats')]
    public function quickStats(): JsonResponse
    {
        return new JsonResponse([
            'forge' => [
                'value' => $this->keyFigures->openIssues(),
            ],
            'gerrit' => [
                'value' => $this->keyFigures->readyForReview(),
                'link' => $this->generateUrl('reviews_ready'),
            ],
        ]);
    }
}
