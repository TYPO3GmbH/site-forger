<?php namespace App\Redmine;

class Connection {
	/**
	 * @var \Illuminate\Config\Repository
	 */
	protected $config;

	/**
	 * @var \Redmine\Client;
	 */
	protected $redmineClient;

	/**
	 * @var \Redmine\Client;
	 */
	protected $elevatedRedmineClient;

	/**
	 * @param \Illuminate\Config\Repository $config
	 */
	public function __construct(\Illuminate\Config\Repository $config) {
		$this->config = $config;

		$this->redmineClient = new \Redmine\Client(
			$this->config->get('app.redmine.url'),
			$this->config->get('app.redmine.apiKey')
		);

		$this->elevatedRedmineClient = new \Redmine\Client(
			$this->config->get('app.redmine.url'),
			$this->config->get('app.redmine.elevatedApiKey')
		);
	}

	/**
	 * @return \Redmine\Client
	 */
	public function getRedmineClient() {
		return $this->redmineClient;
	}

    /**
     * @return \Redmine\Client
     */
    public function getElevatedRedmineClient()
    {
        return $this->elevatedRedmineClient;
    }
}
