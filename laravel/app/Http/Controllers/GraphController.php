<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;
use Illuminate\Http\Response;

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
     * Returns Graph JSON
     *
     * @param  string    $type     The Document Type from Elastic to work on
     * @param  string    $graph    The Name of the Graph Class to call
     * @return Response
     */
    public function index($type = 'Gerrit', $graph = 'Velocity')
    {
        $graph = $this->getGraphName($graph, $type);
        return $graph->render();
    }

    /**
     * @param  string $graph    The Name of the Graph Class to call
     * @param  string $docType  The Document Type from Elastic to work on
     * @return \App\Http\Controllers\Graph\AbstractGraph
     */
    protected function getGraphName($graph, $docType)
    {
        $graphName = '\\App\\Http\\Controllers\\Graph\\'.$docType.'\\'.$graph;
        $graph = new $graphName($this->connection);
        return $graph;
    }
}
