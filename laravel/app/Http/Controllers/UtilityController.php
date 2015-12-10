<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UtilityController extends Controller
{

    /**
     * @var Connection
     */
    private $connection;

    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getRst()
    {
        return $this->view->render('rst');
    }
    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getHelp()
    {
        return $this->view->render('help');
    }
    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getCalendar()
    {
        return $this->view->render('calendar');
    }

    public function getIssue(Connection $connection, Request $request)
    {
        $this->connection = $connection;
        $issueId = 0;
        if ($request->has('issueId')) {
            $issueId = $request->input('issueId');
        }
        $issueData = $this->findIssue($issueId);
        return json_encode($issueData);
    }

    /**
     * @param int $issueId
     *
     * @return array
     */
    protected function findIssue($issueId)
    {
        $index = $this->connection->getElasticIndex();
        $forger = $index->getType('issue');
        try {
            $res = $forger->getDocument($issueId);
            $data = $res->getData();
        } catch (\Exception $e) {
            $data = array(
                'error' => 'Issue #' . $issueId . ' was not found.' . $e->getMessage()
            );
        }
        return $data;
    }
}
