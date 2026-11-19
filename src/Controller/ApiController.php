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
use App\Elastic\ElasticUtility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class ApiController extends AbstractController
{
    #[Route(path: '/api/{type}/{documentId}', name: 'api')]
    public function index(string $type = 'issue', int $documentId = 0): JsonResponse
    {
        try {
            $output = ElasticUtility::getIndexByName($type)->getDocument($documentId);
        } catch (\Exception) {
            return new JsonResponse('Document not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($output->getData());
    }

    #[Route(path: '/quick_stats', name: 'quick_stats')]
    public function quickStats(): JsonResponse
    {
        $index = ElasticUtility::getIndexByName('issue');
        $openIssuesQuery = [
            'query' => [
                'bool' => [
                    'must_not' => [
                        ['terms' => ['status.name' => ['Closed', 'Rejected', 'Resolved']]],
                    ],
                ],
            ],
            'size' => 0,
        ];
        $openIssues = $index->search($openIssuesQuery)->getTotalHits();

        $openReviewsQuery = [
            'query' => [
                'bool' => [
                    'must_not' => [
                        ['terms' => ['reviewstatus' => ['ABANDONED', 'MERGED']]],
                        ['term' => ['work_in_progress' => true]],
                    ],
                ],
            ],
            'size' => 0,
        ];
        $index = ElasticUtility::getIndexByName('review');
        $openReviews = $index->search($openReviewsQuery)->getTotalHits();

        return new JsonResponse([
            'forge' => [
                'value' => $openIssues,
            ],
            'gerrit' => [
                'value' => $openReviews,
                'link' => $this->generateUrl('gerrit_index'),
            ],
        ]);
    }

    #[Route(path: '/chart/{chartName}', name: 'chart')]
    public function chart(string $chartName): JsonResponse
    {
        $indexName = ('ticket_progress' === $chartName) ? 'issue' : 'review';
        $index = ElasticUtility::getIndexByName($indexName);
        $chartInstance = ChartRegistry::getChartInstance($chartName, $index);
        $data = $chartInstance->getData();

        return new JsonResponse($data);
    }
}
