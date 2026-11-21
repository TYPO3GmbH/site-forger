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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limits the requests to the public API for each client address, and lets clients and proxies cache the answers.
 *
 * The API needs no login, and each request asks Elasticsearch. The limit is in config/packages/rate_limiter.yaml.
 * The data changes with the imports, every five minutes, so an answer may be cached for a minute.
 */
final readonly class ApiRateLimitSubscriber implements EventSubscriberInterface
{
    public const string ROUTE_PREFIX = 'api_v1_';

    public const int CACHE_SECONDS = 60;

    private const string LIMIT_ATTRIBUTE = '_api_rate_limit';

    public function __construct(
        private RateLimiterFactoryInterface $publicApiLimiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the router, which sets the route
            KernelEvents::REQUEST => ['onRequest', 16],
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isApi($request)) {
            return;
        }
        $limit = $this->publicApiLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        $request->attributes->set(self::LIMIT_ATTRIBUTE, $limit);
        if ($limit->isAccepted()) {
            return;
        }
        $response = new JsonResponse(
            new Error('Too many requests. Try again in a minute, or cache the answers.'),
            Response::HTTP_TOO_MANY_REQUESTS,
        );
        $response->headers->set(
            'Retry-After',
            (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
        );
        $event->setResponse($response);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isApi($request)) {
            return;
        }
        $response = $event->getResponse();
        $limit = $request->attributes->get(self::LIMIT_ATTRIBUTE);
        if ($limit instanceof RateLimit) {
            $response->headers->set('X-RateLimit-Limit', (string) $limit->getLimit());
            $response->headers->set('X-RateLimit-Remaining', (string) $limit->getRemainingTokens());
        }
        // Other sites and tools in the browser may read the public data, also the answer that they asked too often
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set(
            'Access-Control-Expose-Headers',
            'Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining',
        );
        if (Response::HTTP_OK === $response->getStatusCode()) {
            $response->setPublic();
            $response->setMaxAge(self::CACHE_SECONDS);
        }
    }

    private static function isApi(Request $request): bool
    {
        return str_starts_with((string) $request->attributes->get('_route'), self::ROUTE_PREFIX);
    }
}
