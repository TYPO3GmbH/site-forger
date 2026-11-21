<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use T3G\Bundle\Keycloak\EventSubscriber\RequestSubscriber;

/**
 * The subscriber of the Keycloak bundle, without the routes that are stateless, like the public API.
 *
 * The bundle refreshes the access token of the session on every request. That opens a session, sends a cookie and
 * makes every answer private, so no proxy may cache it. A stateless route knows no login, so it has no token to
 * refresh. config/services.yaml puts this class in place of the one of the bundle, so it is no service of its own.
 */
#[Exclude]
class StatelessAwareKeycloakSubscriber extends RequestSubscriber
{
    #[\Override]
    public function refreshAccessToken(RequestEvent $event): void
    {
        if (true === $event->getRequest()->attributes->get('_stateless')) {
            return;
        }
        parent::refreshAccessToken($event);
    }
}
