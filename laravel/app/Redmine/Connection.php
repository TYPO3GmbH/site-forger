<?php

namespace App\Redmine;

use Illuminate\Config\Repository;
use Redmine\Client;

class Connection
{
    /**
     * @var Repository
     */
    protected $config;

    /**
     * @var Client;
     */
    protected $redmineClient;

    /**
     * @var Client;
     */
    protected $elevatedRedmineClient;

    /**
     * @param Repository $config
     */
    public function __construct(Repository $config)
    {
        $this->config = $config;

        $this->redmineClient = new Client(
            $this->config->get('app.redmine.url'),
            $this->config->get('app.redmine.apiKey')
        );

        $this->elevatedRedmineClient = new Client(
            $this->config->get('app.redmine.url'),
            $this->config->get('app.redmine.elevatedApiKey')
        );
    }

    /**
     * @return Client
     */
    public function getRedmineClient() : Client
    {
        return $this->redmineClient;
    }

    /**
     * @return Client
     */
    public function getElevatedRedmineClient() : Client
    {
        return $this->elevatedRedmineClient;
    }
}
