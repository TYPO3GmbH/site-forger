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
        $this->view->assign('sitekey', config('app.slack.recaptcha_site'));
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
        $recaptcha = $request->get('g-recaptcha-response');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) && trim($firstName) === '' && trim($lastName) === '') {
            return redirect('index');
        }
        if (!$this->checkRecaptcha($recaptcha)) {
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
     * @param string $email
     * @param string $firstName
     * @param string $lastName
     *
     * @return bool
     */
    protected function sendApiRequest($email, $firstName, $lastName)
    {
        $client = new Client([
            'base_uri' => config('app.slack.teamUrl'),
        ]);

        $client->request('POST', '/api/users.admin.invite?t='.time(), [
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
     * @param string $recaptcha
     *
     * @return bool
     */
    protected function checkRecaptcha($recaptcha)
    {
        $client = new Client([
            'base_uri' => 'https://www.google.com/',
        ]);

        $response = $client->post('recaptcha/api/siteverify', [
            'form_params' => [
                'secret' => config('app.slack.recaptcha_secret'),
                'response' => $recaptcha,
            ],
        ]);

        $body = (string) $response->getBody();
        $result = json_decode($body);

        return $result->success;
    }
}
