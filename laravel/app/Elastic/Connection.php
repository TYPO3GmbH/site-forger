<?php namespace App\Elastic;

class Connection
{
    /**
     * @var \Illuminate\Config\Repository
     */
    protected $config;

    /**
     * @var \Elastica\Client
     */
    protected $elasticClient;

    /**
     * @var \Elastica\Index;
     */
    protected $elasticIndex;

    /**
     * @param \Illuminate\Config\Repository $config
     */
    public function __construct(\Illuminate\Config\Repository $config)
    {
        $this->config = $config;

        $elasticConfig = $this->config->get('app.elastic');
        if (!empty($elasticConfig['username']) && !empty($elasticConfig['password'])) {
            $elasticConfig['headers'] = array(
                'Authorization' => 'Basic ' .
                    base64_encode($elasticConfig['username'] . ':' . $elasticConfig['password']) . '=='
            );
        }

        $this->elasticClient = new \Elastica\Client($elasticConfig);
        $this->elasticIndex = $this->elasticClient->getIndex($this->config->get('app.elastic.index'));
    }

    /**
     * @return \Elastica\Client
     */
    public function getElasticClient()
    {
        return $this->elasticClient;
    }

    /**
     * @return \Elastica\Index
     */
    public function getElasticIndex()
    {
        return $this->elasticIndex;
    }
}
