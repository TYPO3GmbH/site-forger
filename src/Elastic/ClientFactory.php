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

/**
 * Builds the Elastica client from the settings ES_HOST, ES_PORT, ES_PATH, ES_TRANSPORT, ES_USER and ES_PASSWORD.
 *
 * Elastica 8 takes the address as one URL. The settings stay as they were, so the servers keep their .env.local.
 */
final class ClientFactory
{
    /**
     * @param string $transport "Http" or "Https", as Elastica 7 named its transports
     * @param int    $timeout   seconds for a whole request, 0 for no limit
     */
    public static function create(
        string $host,
        string $port,
        string $path,
        string $transport,
        string $user,
        string $password,
        int $timeout = 0,
        int $connectTimeout = 0,
    ): Client {
        $config = ['hosts' => [self::url($host, $port, $path, $transport)]];
        // Without a user, no empty login goes along with each request
        if ('' !== $user) {
            $config['username'] = $user;
            $config['password'] = $password;
        }
        $options = array_filter(['timeout' => $timeout, 'connect_timeout' => $connectTimeout]);
        if ([] !== $options) {
            $config['transport_config'] = ['http_client_options' => $options];
        }

        return new Client($config);
    }

    public static function url(string $host, string $port, string $path, string $transport): string
    {
        $scheme = 'https' === strtolower($transport) ? 'https' : 'http';
        $address = '' === $port ? $host : $host . ':' . $port;

        return $scheme . '://' . $address . ('' === $path ? '' : '/' . trim($path, '/'));
    }
}
