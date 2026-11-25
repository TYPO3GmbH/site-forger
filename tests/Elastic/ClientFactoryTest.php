<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Elastic;

use App\Elastic\ClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Elastica 8 takes one URL. The servers keep the settings of Elastica 7 in their .env.local.
 */
final class ClientFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function settings(): iterable
    {
        yield 'dev' => ['forger.ddev.site', '9200', '', 'Http', 'http://forger.ddev.site:9200'];
        yield 'a server' => ['127.0.0.1', '9212', '', 'Http', 'http://127.0.0.1:9212'];
        yield 'with TLS' => ['es.example.org', '443', '', 'Https', 'https://es.example.org:443'];
        yield 'behind a path' => ['example.org', '', '/es/', 'Http', 'http://example.org/es'];
    }

    #[DataProvider('settings')]
    public function testTheSettingsOfElastica7MakeTheUrl(
        string $host,
        string $port,
        string $path,
        string $transport,
        string $url,
    ): void {
        self::assertSame($url, ClientFactory::url($host, $port, $path, $transport));
    }

    public function testWithoutAUserNoLoginGoesAlong(): void
    {
        $client = ClientFactory::create('127.0.0.1', '9200', '', 'Http', '', '');

        self::assertNull($client->getConfigValue('username'));
    }

    public function testAUserGoesAlongWithItsPassword(): void
    {
        $client = ClientFactory::create('127.0.0.1', '9200', '', 'Https', 'forger', 'secret');

        self::assertSame('forger', $client->getConfigValue('username'));
        self::assertSame('secret', $client->getConfigValue('password'));
    }
}
