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
 * Index names follow the Elasticorn convention "<type>_<language>", for example "issue_english".
 */
final readonly class Indices
{
    private const string LANGUAGE = 'english';

    public function __construct(
        private Client $client,
    ) {
    }

    public function get(string $type): Index
    {
        return $this->client->getIndex($type . '_' . self::LANGUAGE);
    }

    public function client(): Client
    {
        return $this->client;
    }
}
