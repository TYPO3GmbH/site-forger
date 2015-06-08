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
	 * @param \Illuminate\Config\Repository $config
	 */
	public function __construct(\Illuminate\Config\Repository $config) {
		$this->config = $config;

		$this->redmineClient = new \Redmine\Client(
			$this->config->get('app.redmine.url'),
			$this->config->get('app.redmine.apiKey')
		);
	}

	/**
	 * @return \Redmine\Client
	 */
	public function getRedmineClient() {
		return $this->redmineClient;
	}
}
