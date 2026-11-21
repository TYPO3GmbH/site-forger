<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Security;

use App\Security\StatelessAwareKeycloakSubscriber;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use T3G\Bundle\Keycloak\Service\AccessTokenRefresher;

final class StatelessAwareKeycloakSubscriberTest extends TestCase
{
    public function testLeavesTheSessionOfAStatelessRouteAlone(): void
    {
        $session = self::refreshFor(stateless: true);

        self::assertFalse($session->isStarted());
    }

    public function testRefreshesTheTokenOnTheOtherRoutes(): void
    {
        // The bundle reads the token from the session, which starts it
        $session = self::refreshFor(stateless: false);

        self::assertTrue($session->isStarted());
    }

    private static function refreshFor(bool $stateless): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($session);
        $request->attributes->set('_stateless', $stateless);
        $requests = new RequestStack();
        $requests->push($request);
        $subscriber = new StatelessAwareKeycloakSubscriber(
            new AccessTokenRefresher(new ClientRegistry(new Container(), []), $requests),
        );

        $subscriber->refreshAccessToken(new RequestEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST));

        return $session;
    }

    private static function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }
}
