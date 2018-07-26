<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;

/**
 * Class SlackController.
 */
class SlackController extends Controller
{
    /**
     * Show the index screen to the user.
     *
     * @return string
     */
    public function getIndex() : string
    {
        $this->view->assign('sitekey', config('app.slack.recaptcha_site'));
        $this->view->assign('csrf_token', csrf_token());

        return $this->view->render('index');
    }

    /**
     * @param Request $request
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|string
     */
    public function sendInvite(Request $request)
    {
        $email = $request->get('email');
        $firstName = $request->get('firstName');
        $lastName = $request->get('lastName');
        $reCaptcha = $request->get('g-recaptcha-response');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) && trim($firstName) === '' && trim($lastName) === '') {
            return redirect('index');
        }
        if (!$this->checkReCaptcha($reCaptcha)) {
            return redirect('slack');
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
     * @param $email
     * @param $firstName
     * @param $lastName
     */
    protected function sendApiRequest($email, $firstName, $lastName)
    {
        $client = new Client([
            'base_uri' => config('app.slack.teamUrl'),
        ]);

        $client->request('POST', '/api/users.admin.invite?t=' . time(), [
            'form_params' => [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'token' => config('app.slack.token'),
                'set_active' => 'true',
            ],
        ]);
    }

    /**
     * @param string $reCaptcha
     *
     * @return bool
     */
    protected function checkReCaptcha($reCaptcha) : bool
    {
        $client = new Client([
            'base_uri' => 'https://www.google.com/',
        ]);

        $response = $client->post('recaptcha/api/siteverify', [
            'form_params' => [
                'secret' => config('app.slack.recaptcha_secret'),
                'response' => $reCaptcha,
            ],
        ]);

        $body = (string) $response->getBody();

        return json_decode($body)->success;
    }
}
