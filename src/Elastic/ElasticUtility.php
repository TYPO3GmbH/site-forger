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
use Elastica\Index;

/**
 * Resolves Elasticsearch indices configured via the ES_* environment variables.
 *
 * Index names follow the Elasticorn convention "<type>_<language>", e.g. "issue_english".
 */
final class ElasticUtility
{
    private const LANGUAGE = 'english';

    private static ?Client $client = null;

    public static function getIndexByName(string $name): Index
    {
        return self::getClient()->getIndex($name . '_' . self::LANGUAGE);
    }

    private static function getClient(): Client
    {
        return self::$client ??= new Client([
            'host' => $_ENV['ES_HOST'],
            'port' => $_ENV['ES_PORT'],
            'path' => $_ENV['ES_PATH'],
            'transport' => $_ENV['ES_TRANSPORT'],
            'username' => $_ENV['ES_USER'],
            'password' => $_ENV['ES_PASSWORD'],
        ]);
    }
}
