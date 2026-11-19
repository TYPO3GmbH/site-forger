<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Elastic\ElasticsearchVersion;
use App\Elastic\ElasticUtility;
use App\Elastic\IndexSetup;
use App\Elastic\PendingImport;
use App\Enum\SystemCheckEnum;
use Elastica\Cluster\Health;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What Forger runs on and whether its data is complete: Elasticsearch, the indices, the documents waiting
 * for a full import, PHP and Symfony. Only reads, also when Elasticsearch cannot be reached.
 */
#[IsGranted('ROLE_ADMIN')]
class SystemController extends AbstractController
{
    public function __construct(
        private readonly IndexSetup $indexSetup,
        private readonly KernelInterface $kernel,
    ) {
    }

    #[Route(path: '/management/system', name: 'management_system')]
    public function index(): Response
    {
        $elasticsearch = $this->elasticsearch();
        $indices = $elasticsearch['reachable'] ? $this->indices() : [];
        $pending = $elasticsearch['reachable'] ? $this->pendingImport() : [];

        return $this->render('management/system/index.html.twig', [
            'elasticsearch' => $elasticsearch,
            'indices' => $indices,
            'pending' => $pending,
            'application' => [
                'php' => PHP_VERSION,
                'symfony' => Kernel::VERSION,
                'environment' => $this->kernel->getEnvironment(),
                'debug' => $this->kernel->isDebug(),
            ],
            'overall' => SystemCheckEnum::worst(
                $elasticsearch['versionResult'],
                $elasticsearch['healthResult'],
                ...array_column($indices, 'result'),
                ...array_column($pending, 'result'),
            ),
            'minimumVersion' => ElasticsearchVersion::MINIMUM,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function elasticsearch(): array
    {
        try {
            $client = ElasticUtility::getClient();
            $version = $client->getVersion();
            $health = new Health($client);
        } catch (\Throwable $e) {
            return [
                'reachable' => false,
                'error' => $e->getMessage(),
                'version' => null,
                'versionResult' => SystemCheckEnum::PROBLEM,
                'healthResult' => SystemCheckEnum::PROBLEM,
            ];
        }

        return [
            'reachable' => true,
            'error' => null,
            'version' => $version,
            'versionResult' => ElasticsearchVersion::result($version),
            'cluster' => $health->getClusterName(),
            'health' => $health->getStatus(),
            'healthResult' => match ($health->getStatus()) {
                'green' => SystemCheckEnum::OK,
                // A single node cannot place the replicas, the data is complete
                'yellow' => 1 === $health->getNumberOfNodes() ? SystemCheckEnum::OK : SystemCheckEnum::ATTENTION,
                default => SystemCheckEnum::PROBLEM,
            },
            'nodes' => $health->getNumberOfNodes(),
            'unassignedShards' => $health->getUnassignedShards(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function indices(): array
    {
        $indices = [];
        foreach ($this->indexSetup->describeAll() as $alias => $description) {
            $index = null === $description['index'] ? null : ElasticUtility::getClient()->getIndex($description['index']);
            $indices[] = [
                'alias' => $alias,
                'index' => $description['index'],
                'configuration' => $description['configuration'],
                'result' => match ($description['configuration']) {
                    IndexSetup::CONFIGURATION_CURRENT => SystemCheckEnum::OK,
                    IndexSetup::CONFIGURATION_UNKNOWN => SystemCheckEnum::UNKNOWN,
                    IndexSetup::CONFIGURATION_OUTDATED => SystemCheckEnum::ATTENTION,
                    default => SystemCheckEnum::PROBLEM,
                },
                'documents' => $index?->count(),
                'bytes' => $index?->getStats()->get('_all', 'primaries', 'store', 'size_in_bytes'),
            ];
        }

        return $indices;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingImport(): array
    {
        $pending = [];
        foreach (PendingImport::checks() as $check) {
            $index = ElasticUtility::getIndexByName($check['index']);
            $matches = $index->count(['query' => $check['query']]);
            $documents = $index->count();
            $pending[] = $check + [
                'matches' => $matches,
                'documents' => $documents,
                'result' => PendingImport::result($check['kind'], $matches, $documents),
            ];
        }

        return $pending;
    }
}
