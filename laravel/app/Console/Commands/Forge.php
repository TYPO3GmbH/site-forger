<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Console\Commands;

use App\Elastic\Connection as ElasticConnection;
use App\Redmine\Connection as RedmineConnection;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;

class Forge extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'forge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Forge Command';

    /**
     * @var Repository
     */
    protected $config;

    /**
     * @var \Redmine\Client
     */
    protected $redmineClient;

    /**
     * @var \Elastica\Client
     */
    protected $elasticClient;

    /**
     * @var \Elastica\Index;
     */
    protected $elasticIndex;

    /**
     * @var \App\Elastic\Connection
     */
    protected $elasticConnection;

    /**
     * @var \App\Redmine\Connection
     */
    protected $redmineConnection;

    /**
     * @var array
     */
    protected $settings;

    public function __construct(Repository $config, ElasticConnection $elasticConnection, RedmineConnection $redmineConnection)
    {
        parent::__construct();
        $this->config = $config;
        $this->elasticConnection = $elasticConnection;
        $this->redmineConnection = $redmineConnection;
    }

    /**
     * Execute the command.
     */
    public function handle()
    {
        $this->startUp();
        $this->handleTest();
    }

    protected function handleTest()
    {
        $this->comment('Start testings...');
        $this->comment('Testing Redmine...');
        $json = $this->redmineClient->get('/issues.json');
        if (is_array($json)) {
            $this->info('Connected to Redmine successful!, found: ' . $json['total_count'] . ' issues');
        } else {
            $this->error('Connection to Redmine not possible!');
        }
        $this->comment('Testing Elastic...');
        $result = $this->elasticIndex->search('index');
        if ($result->count()) {
            $this->info('Connected to Elastic successful!, found: ' . $result->count() . ' documents');
        } else {
            $this->error('Connection to Elastic not possible!');
        }
    }

    /**
     * Initializes all client connections to forge and to Elasticsearch.
     */
    protected function startUp()
    {
        $this->redmineClient = $this->redmineConnection->getRedmineClient();
        $this->elasticClient = $this->elasticConnection->getElasticClient();
        $this->elasticIndex = $this->elasticConnection->getElasticIndex();
    }
}
