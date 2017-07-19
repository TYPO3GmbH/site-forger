<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Class SprintController.
 */
class ApiController extends BaseController
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * ApiController constructor.
     *
     * @param Connection $connection
     * @param Request $request
     */
    public function __construct(Connection $connection, Request $request)
    {
        date_default_timezone_set('UTC');
        $this->connection = $connection;
    }

    /**
     * Show the index screen to the user.
     *
     * @param string $type
     * @param int    $id
     *
     * @return string
     * @throws \Elastica\Exception\NotFoundException
     */
    public function index(string $type = 'Issue', int $id = 0) : string
    {
        try {
            $output = $this->connection->getElasticIndex()->getType($type)->getDocument($id);
        } catch (\Exception $e) {
            return \Response::json('Document not found', 404);
        }
        return \Response::json($output->getData());
    }
}
