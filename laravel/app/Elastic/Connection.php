<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Elastic;

use Elastica\Client;
use Elastica\Index;
use Illuminate\Config\Repository;

class Connection
{
    /**
     * @var Repository
     */
    protected $config;

    /**
     * @var Client
     */
    protected $elasticClient;

    /**
     * @var Index;
     */
    protected $elasticIndex;

    /**
     * @param Repository $config
     */
    public function __construct(Repository $config)
    {
        $this->config = $config;

        $elasticConfig = $this->config->get('app.elastic');
        if (!empty($elasticConfig['username']) && !empty($elasticConfig['password'])) {
            $elasticConfig['headers'] = [
                'Authorization' => 'Basic ' .
                    base64_encode($elasticConfig['username'] . ':' . $elasticConfig['password']) . '==',
            ];
        }

        $this->elasticClient = new Client($elasticConfig);
        $this->elasticIndex = $this->elasticClient->getIndex($this->config->get('app.elastic.index'));
    }

    /**
     * @return Client
     */
    public function getElasticClient() : Client
    {
        return $this->elasticClient;
    }

    /**
     * @return Index
     */
    public function getElasticIndex() : Index
    {
        return $this->elasticIndex;
    }
}
