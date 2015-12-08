<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class UtilityController extends Controller
{

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
}
