<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use Elastic\Elasticsearch\Response\Elasticsearch;
use Http\Promise\Promise;

/**
 * The answer of an endpoint of the Elasticsearch client, like mget() or ping().
 *
 * The endpoints return a promise in the async mode of the client. Forger never turns it on.
 */
final class SyncAnswer
{
    public static function of(Elasticsearch|Promise $answer): Elasticsearch
    {
        if ($answer instanceof Promise) {
            throw new \LogicException('The Elasticsearch client answered with a promise, its async mode is on.');
        }

        return $answer;
    }
}
