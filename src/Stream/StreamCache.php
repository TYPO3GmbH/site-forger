<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Stream;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Keeps the statistics of the activity for a minute. The API, the statistics page and the dashboard ask for the same
 * figures, and each answer costs several aggregations in Elasticsearch. A window that ends now ends at the full
 * minute, see StreamWindow::now(), so the same question gives the same key for a minute.
 */
final readonly class StreamCache
{
    public const int LIFETIME = 60;

    public function __construct(
        #[Autowire(service: 'cache.stream')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @template T
     *
     * @param list<mixed>   $arguments what the result depends on: filters, windows, enums
     * @param callable(): T $compute
     *
     * @return T
     */
    public function get(string $name, array $arguments, callable $compute): mixed
    {
        $key = $name . '.' . hash('xxh128', serialize($arguments));

        return $this->cache->get($key, static function (ItemInterface $item) use ($compute): mixed {
            $item->expiresAfter(self::LIFETIME);

            return $compute();
        });
    }
}
