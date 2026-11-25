<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use Elastica\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Whether Elasticsearch answers. MaintenanceSubscriber asks this on every request.
 *
 * The client of this class has short timeouts (see services.yaml), so a server that does not answer costs a few
 * seconds and not the timeout of a search. The answer is kept for some seconds, so not every request asks.
 */
final readonly class Availability
{
    public const int CACHE_SECONDS = 15;

    private const string CACHE_KEY = 'elasticsearch_available';

    public function __construct(
        #[Autowire(service: 'app.elasticsearch.ping_client')]
        private Client $client,
        private CacheInterface $cache,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): bool {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $this->ping();
        });
    }

    private function ping(): bool
    {
        try {
            return SyncAnswer::of($this->client->ping())->asBool();
        } catch (\Throwable) {
            return false;
        }
    }
}
