<?php

namespace App\Http\Controllers;

use App\Elastic\Connection;

/**
 * Class BackportController.
 */
class BackportController extends Controller
{
    /**
     * @var string
     */
    protected $context;

    /**
     * Show the index screen to the user.
     *
     * @return string
     *
     * @throws \Elastica\Exception\InvalidException
     */
    public function getStatus() : string
    {
        if (is_file(storage_path() . '/app/Cache/backports.json')) {
            $backports = json_decode(file_get_contents(storage_path() . '/app/Cache/backports.json'), true);
            $this->view->assign('backports', $backports);
            $this->view->assign('sum', \count($backports));
        }
        return $this->view->render('status');
    }
}
