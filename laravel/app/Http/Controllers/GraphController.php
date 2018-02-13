<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;
use Illuminate\Support\Facades\Config;

/**
 * Class GraphController.
 */
class GraphController extends Controller
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * AbstractGraph constructor.
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    /**
     * Returns Graph JSON.
     *
     * @param string $type  The Document Type from Elastic to work on
     * @param string $graph The Name of the Graph Class to call
     *
     * @return string
     */
    public function index(string $type = 'Gerrit', string $graph = 'Velocity') : string
    {
        Config::set('debugbar.enabled', false);
        return json_encode($this->getGraphName($graph, $type)->render());
    }

    /**
     * @param string $graph   The Name of the Graph Class to call
     * @param string $docType The Document Type from Elastic to work on
     *
     * @return \App\Http\Controllers\Graph\AbstractGraph
     */
    protected function getGraphName($graph, $docType) : Graph\AbstractGraph
    {
        $graphName = '\\App\\Http\\Controllers\\Graph\\' . $docType . '\\' . $graph;
        return new $graphName($this->connection);
    }
}
