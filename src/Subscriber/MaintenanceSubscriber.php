<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Subscriber;

use App\Api\Model\Error;
use App\Elastic\Availability;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;

/**
 * Shows a maintenance page while Elasticsearch does not answer, for example during its upgrade.
 *
 * Admins keep using Forger, to look at the system page. The login and the logout stay open, so an admin can log in.
 * The API answers with an error in JSON.
 */
final readonly class MaintenanceSubscriber implements EventSubscriberInterface
{
    /**
     * Set on the request of an admin while Elasticsearch does not answer. base.html.twig then shows a note.
     */
    public const string ATTRIBUTE = '_maintenance';

    public const int RETRY_SECONDS = 60;

    private const array OPEN_ROUTES = [
        't3g_keycloak_login',
        't3g_keycloak_oauthCallback',
        't3g_keycloak_logout',
        'logout',
        '_logout_main',
        'dev_login',
    ];

    public function __construct(
        private Availability $elasticsearch,
        private AuthorizationCheckerInterface $authorization,
        private Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the firewall (8), which knows the user
            KernelEvents::REQUEST => ['onRequest', 4],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || self::isOpen($request) || $this->elasticsearch->isAvailable()) {
            return;
        }
        if ($this->authorization->isGranted('ROLE_ADMIN')) {
            $request->attributes->set(self::ATTRIBUTE, true);

            return;
        }
        $response = str_starts_with((string) $request->attributes->get('_route'), ApiRateLimitSubscriber::ROUTE_PREFIX)
            ? new JsonResponse(
                new Error('Forger is under maintenance. Try again in a few minutes.'),
                Response::HTTP_SERVICE_UNAVAILABLE,
            )
            : new Response(
                $this->twig->render('maintenance/index.html.twig'),
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        $response->headers->set('Retry-After', (string) self::RETRY_SECONDS);
        $response->headers->set('Cache-Control', 'no-store');
        $event->setResponse($response);
    }

    private static function isOpen(Request $request): bool
    {
        $route = (string) $request->attributes->get('_route');

        return in_array($route, self::OPEN_ROUTES, true)
            || str_starts_with($route, '_profiler')
            || str_starts_with($route, '_wdt');
    }
}
