<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SlackController extends Controller
{

    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function getIndex()
    {
        return $this->view->render('index');
    }
}
