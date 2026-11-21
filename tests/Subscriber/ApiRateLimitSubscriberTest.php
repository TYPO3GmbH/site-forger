<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Subscriber;

use App\Subscriber\ApiRateLimitSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ApiRateLimitSubscriberTest extends TestCase
{
    public function testAcceptsTheRequestsUpToTheLimit(): void
    {
        $subscriber = self::subscriber(2);
        $request = self::request('api_v1_issues');

        $event = self::requestEvent($request);
        $subscriber->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAnswersTooManyRequestsBeyondTheLimit(): void
    {
        $subscriber = self::subscriber(1);
        $subscriber->onRequest(self::requestEvent(self::request('api_v1_issues')));

        $event = self::requestEvent(self::request('api_v1_issues'));
        $subscriber->onRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        self::assertStringContainsString('"error"', (string) $response->getContent());
    }

    public function testCountsEachAddressOnItsOwn(): void
    {
        $subscriber = self::subscriber(1);
        $subscriber->onRequest(self::requestEvent(self::request('api_v1_issues', '192.0.2.1')));

        $event = self::requestEvent(self::request('api_v1_issues', '192.0.2.2'));
        $subscriber->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testLeavesTheOtherRoutesAlone(): void
    {
        $subscriber = self::subscriber(1);
        $request = self::request('dashboard_index');
        $subscriber->onRequest(self::requestEvent($request));
        $event = self::requestEvent($request);
        $subscriber->onRequest($event);
        $response = new Response();
        $subscriber->onResponse(self::responseEvent($request, $response));

        self::assertNull($event->getResponse());
        self::assertFalse($response->headers->has('X-RateLimit-Limit'));
        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function testMarksASuccessfulAnswerAsPublicForAMinute(): void
    {
        $subscriber = self::subscriber(5);
        $request = self::request('api_v1_issue');
        $subscriber->onRequest(self::requestEvent($request));
        $response = new JsonResponse([]);

        $subscriber->onResponse(self::responseEvent($request, $response));

        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame(ApiRateLimitSubscriber::CACHE_SECONDS, $response->getMaxAge());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('5', $response->headers->get('X-RateLimit-Limit'));
        self::assertSame('4', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testDoesNotCacheAnError(): void
    {
        $subscriber = self::subscriber(5);
        $request = self::request('api_v1_issue');
        $response = new JsonResponse(['error' => 'Issue #1 is not in Forger.'], Response::HTTP_NOT_FOUND);

        $subscriber->onResponse(self::responseEvent($request, $response));

        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    private static function subscriber(int $limit): ApiRateLimitSubscriber
    {
        return new ApiRateLimitSubscriber(new RateLimiterFactory([
            'id' => 'public_api',
            'policy' => 'token_bucket',
            'limit' => $limit,
            'rate' => ['interval' => '1 minute', 'amount' => $limit],
        ], new InMemoryStorage()));
    }

    private static function request(string $route, string $address = '192.0.2.1'): Request
    {
        $request = Request::create('/', server: ['REMOTE_ADDR' => $address]);
        $request->attributes->set('_route', $route);

        return $request;
    }

    private static function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private static function responseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(self::kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
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
