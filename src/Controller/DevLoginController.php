<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Controller;

use App\Security\Dev\DevLoginAuthenticator;
use App\Security\Dev\DevUserRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Login page with the fixed dev users and the Keycloak login (dev only).
 *
 * The POST request is handled by the DevLoginAuthenticator.
 */
#[When(env: 'dev')]
class DevLoginController extends AbstractController
{
    #[Route(path: '/_dev/login', name: DevLoginAuthenticator::LOGIN_ROUTE, methods: ['GET', 'POST'], env: 'dev')]
    public function login(DevUserRegistry $devUserRegistry, AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/dev_login.html.twig', [
            'users' => $devUserRegistry->all(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'csrf_token_id' => DevLoginAuthenticator::CSRF_TOKEN_ID,
        ]);
    }
}
