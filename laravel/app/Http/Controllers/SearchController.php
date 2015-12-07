<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SearchController extends Controller
{

    /**
     * Show the index screen to the user.
     *
     * @return Response
     */
    public function index()
    {
        return $this->view->render('index');
    }
}
