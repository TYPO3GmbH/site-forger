<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Charts\ChartRegistry;
use App\Elastic\Indices;
use App\Report\KeyFigures;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ApiController extends AbstractController
{
    private const array EMAIL_FIELDS = ['email', 'ownerEmail'];

    public function __construct(
        private readonly Indices $indices,
        private readonly ChartRegistry $chartRegistry,
        private readonly KeyFigures $keyFigures,
    ) {
    }

    /**
     * Only the issues and the changes: the index "user" holds email addresses.
     */
    #[Route(path: '/api/{type}/{documentId}', name: 'api', requirements: ['type' => 'issue|review'])]
    public function index(string $type = 'issue', int $documentId = 0): JsonResponse
    {
        try {
            $output = $this->indices->get($type)->getDocument($documentId);
        } catch (\Exception) {
            return new JsonResponse('Document not found', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->withoutEmailAddresses($output->getData()));
    }

    /**
     * Email addresses are stored for avatars only and must not leave the application.
     */
    private function withoutEmailAddresses(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        $data = array_diff_key($data, array_flip(self::EMAIL_FIELDS));

        return array_map($this->withoutEmailAddresses(...), $data);
    }

    #[Route(path: '/quick_stats', name: 'quick_stats')]
    public function quickStats(): JsonResponse
    {
        return new JsonResponse([
            'forge' => [
                'value' => $this->keyFigures->openIssues(),
            ],
            'gerrit' => [
                'value' => $this->keyFigures->openChanges(),
                'link' => $this->generateUrl('reviews_ready'),
            ],
        ]);
    }

    #[Route(path: '/chart/{chartName}', name: 'chart')]
    public function chart(string $chartName): JsonResponse
    {
        try {
            $chartInstance = $this->chartRegistry->get($chartName);
        } catch (\InvalidArgumentException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
        $data = $chartInstance->getData();

        return new JsonResponse($data);
    }
}
