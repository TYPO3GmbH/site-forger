<?php

namespace app\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
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
        $this->view->assign('csrf_token', csrf_token());
        return $this->view->render('index');
    }

    /**
     * @param Request $request
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function sendInvite(Request $request)
    {
        $email = $request->get('email');
        $firstName = $request->get('firstName');
        $lastName = $request->get('lastName');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) && trim($firstName) === '' && trim($lastName) === '') {
            return redirect('index');
        }
        $this->sendApiRequest($email, $firstName, $lastName);
        $this->view->assignMultiple([
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
        ]);
        return $this->view->render('SendInvite');
    }

    /**
     * @param string $email
     * @param string $firstName
     * @param string $lastName
     *
     * @return bool
     */
    protected function sendApiRequest($email, $firstName, $lastName)
    {
        $client = new Client([
            'base_uri' => config('app.slack.teamUrl')
        ]);

        $client->request('POST', '/api/users.admin.invite?t=' . time(), [
            'form_params' => [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'token' => config('app.slack.token'),
                'set_active' => 'true'
            ]
        ]);
    }
}
