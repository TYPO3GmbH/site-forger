<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Elastic\Bucket;
use App\Elastic\ElasticsearchVersion;
use App\Elastic\IndexSetup;
use App\Elastic\Indices;
use App\Enum\SystemCheckEnum;
use App\Import\Backfill\SchemaBackfill;
use App\Import\ImportSchema;
use App\Import\PendingImport;
use App\People\UserHealth;
use App\People\UserStatistics;
use App\Repository\UserRepository;
use App\Setup\ForgeLogin;
use App\Setup\SetupCheck;
use App\Setup\SetupSetting;
use App\Stream\StreamSchema;
use Elastica\Cluster\Health;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * What Forger runs on and whether its data is complete: Elasticsearch, the indices, the documents waiting
 * for a full import, PHP and Symfony. Only reads, also when Elasticsearch cannot be reached.
 *
 * @phpstan-import-type ForgeCheck from ForgeLogin
 */
#[IsGranted('ROLE_ADMIN')]
class SystemController extends AbstractController
{
    public function __construct(
        private readonly Indices $indices,
        private readonly IndexSetup $indexSetup,
        private readonly KernelInterface $kernel,
        private readonly UserRepository $users,
        private readonly ForgeLogin $forgeLogin,
        private readonly SchemaBackfill $backfill,
        private readonly CacheInterface $cache,
        #[Autowire('%kernel.project_dir%/.env')]
        private readonly string $dotEnv,
    ) {
    }

    #[Route(path: '/management/system', name: 'management_system')]
    public function index(): Response
    {
        $elasticsearch = $this->elasticsearch();
        $setup = SetupCheck::settings(
            [...$_ENV, ...$_SERVER],
            $this->dotEnvDefaults(),
            $this->forgeLogin(),
        );
        $indices = $elasticsearch['reachable'] ? $this->indices() : [];
        $pending = $elasticsearch['reachable'] ? $this->pendingImport() : [];
        $statistics = $elasticsearch['reachable'] ? $this->userStatistics() : null;
        $users = null === $statistics ? null : UserHealth::of($statistics, new \DateTimeImmutable());

        // The result of each section of the page, by its title: the tiles at the top and the heads of the sections
        $checks = [
            'Setup' => SystemCheckEnum::worst(
                ...array_map(static fn (SetupSetting $setting): SystemCheckEnum => $setting->result, $setup),
            ),
            'Elasticsearch' => SystemCheckEnum::worst($elasticsearch['versionResult'], $elasticsearch['healthResult']),
            'Indices' => SystemCheckEnum::worst(...array_column($indices, 'result')),
            'Outdated documents' => SystemCheckEnum::worst(...array_column($pending, 'result')),
            'Users' => $users['result'] ?? SystemCheckEnum::OK,
        ];

        return $this->render('management/system/index.html.twig', [
            'elasticsearch' => $elasticsearch,
            'indices' => $indices,
            'pending' => $pending,
            'users' => $users,
            'userStatistics' => $statistics,
            'withoutUsernameCount' => null === $statistics ? 0 : $this->withoutUsernameCount(),
            'application' => [
                'php' => PHP_VERSION,
                'symfony' => Kernel::VERSION,
                'environment' => $this->kernel->getEnvironment(),
                'debug' => $this->kernel->isDebug(),
            ],
            'setup' => $setup,
            'checks' => $checks,
            'overall' => SystemCheckEnum::worst(...array_values($checks)),
            'notOk' => array_keys(array_filter(
                $checks,
                static fn (SystemCheckEnum $result): bool => SystemCheckEnum::OK !== $result,
            )),
            'minimumVersion' => ElasticsearchVersion::MINIMUM,
        ]);
    }

    /**
     * What ForgeLogin::check() tells, kept for some minutes. The check asks Forge up to three times, and each
     * request waits for the rate limit of Forge it shares with the imports: seconds, while an import runs. A
     * problem is kept for a short time only, so the page shows soon that it is solved.
     *
     * @return ForgeCheck
     */
    private function forgeLogin(): array
    {
        return $this->cache->get('system.forge_login', function (ItemInterface $item): array {
            $check = $this->forgeLogin->check();
            // Without a problem the check names the account and nothing else
            $healthy = null === $check['error'] && 2 === count($check);
            $item->expiresAfter($healthy ? 300 : 30);

            return $check;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function elasticsearch(): array
    {
        try {
            $client = $this->indices->client();
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
            $index = null === $description['index'] ? null : $this->indices->client()->getIndex($description['index']);
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
     * The statistics of the users for UserHealth: null if the index "user" cannot be read, the indices show why.
     */
    private function userStatistics(): ?UserStatistics
    {
        try {
            return $this->users->statistics();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * How many accounts of Forge and Gerrit have no username. The tab "No username" of the people lists them, see
     * PeopleController.
     */
    private function withoutUsernameCount(): int
    {
        try {
            return count($this->users->withoutUsername());
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingImport(): array
    {
        $pending = [];
        foreach (PendingImport::checks() as $check) {
            $index = $this->indices->get($check['index']);
            $matches = $index->count(['query' => $check['query']]);
            // Documents without the field are of version 1 of the import, of none of the stream
            $result = $index->search([
                'size' => 0,
                'aggs' => [
                    'versions' => ['terms' => ['field' => ImportSchema::FIELD, 'missing' => 1, 'size' => 100]],
                    'stream' => ['terms' => ['field' => StreamSchema::FIELD, 'missing' => 0, 'size' => 100]],
                ],
            ]);
            $byVersion = Bucket::countsOf($result->getAggregation('versions'));
            $streamVersions = PendingImport::streamVersions(Bucket::countsOf($result->getAggregation('stream')));
            $streamOutdated = array_sum(array_column(
                array_filter($streamVersions, static fn (array $row): bool => 'current' !== $row['state']),
                'documents',
            ));
            $pending[] = $check + [
                'matches' => $matches,
                'versions' => PendingImport::versions($check['index'], $byVersion, $this->backfill),
                'streamVersions' => $streamVersions,
                'documents' => $index->count(),
                'result' => SystemCheckEnum::worst(
                    PendingImport::result($matches),
                    PendingImport::result($streamOutdated),
                ),
            ];
        }

        return $pending;
    }

    /**
     * The values of the committed .env, to tell its placeholders, see SetupCheck.
     *
     * @return array<string, string>
     */
    private function dotEnvDefaults(): array
    {
        $content = is_readable($this->dotEnv) ? file_get_contents($this->dotEnv) : false;

        return false === $content ? [] : (new Dotenv())->parse($content, $this->dotEnv);
    }
}
