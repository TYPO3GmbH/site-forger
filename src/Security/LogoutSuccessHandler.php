<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use T3G\Bundle\Keycloak\Security\KeyCloakAuthenticator;
use T3G\Bundle\Keycloak\Service\OpenIdService;

readonly class LogoutSuccessHandler implements EventSubscriberInterface
{
    public function __construct(
        private OpenIdService $openIdService,
        private string $keycloakBaseUri,
        private string $keycloakRealm,
        private string $keycloakClientId,
        private string $appUrl
    ) {
    }

    public function onLogout(LogoutEvent $logoutEvent): void
    {
        $request = $logoutEvent->getRequest();
        if (!$request->hasSession() || !$request->getSession()->has(KeyCloakAuthenticator::SESSION_KEYCLOAK_ACCESS_TOKEN)) {
            // Not logged in via Keycloak (e.g. a fixed dev user), so there is no Keycloak session to end
            $logoutEvent->setResponse(new RedirectResponse('/'));

            return;
        }

        $realmUrl = rtrim($this->keycloakBaseUri, '/') . '/realms/' . $this->keycloakRealm;
        $endSessionEndpoint = $this->openIdService->getOpenIdConfiguration($realmUrl)['end_session_endpoint'];
        $logoutUrl = $endSessionEndpoint . '?client_id=' . $this->keycloakClientId . '&post_logout_redirect_uri=' . urlencode('https://' . $this->appUrl);

        $logoutEvent->setResponse(new RedirectResponse('/oauth/logout?redirect=' . urlencode($logoutUrl)));
    }

    /**
     * @return array<string, mixed>
     */
    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => ['onLogout', 64]];
    }
}
