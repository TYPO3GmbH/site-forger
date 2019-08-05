<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Client\ElasticClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ApiController extends AbstractController
{
    /**
     * @var ElasticClient
     */
    private $elasticClient;

    /**
     * @var ParameterBagInterface
     */
    private $parameters;

    public function __construct(ElasticClient $elasticClient, ParameterBagInterface $parameters)
    {
        $this->elasticClient = $elasticClient;
        $this->parameters = $parameters;
    }

    /**
     * @Route("/api/{type}/{documentId}", name="api")
     * @param string $type
     * @param int $documentId
     * @return JsonResponse
     */
    public function index(string $type = 'issue', int $documentId = 0): JsonResponse
    {
        try {
            $output = $this->elasticClient->getIndex($this->parameters->get('elastic_index_name'))->getType($type)->getDocument($documentId);
        } catch (\Exception $e) {
            return new JsonResponse('Document not found', 404);
        }
        return new JsonResponse($output->getData());
    }
}
