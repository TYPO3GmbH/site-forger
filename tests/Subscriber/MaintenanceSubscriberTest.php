<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Subscriber;

use App\Elastic\Availability;
use App\Subscriber\MaintenanceSubscriber;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Elastica\Client;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class MaintenanceSubscriberTest extends TestCase
{
    public function testLeavesTheRequestAloneWhileElasticsearchAnswers(): void
    {
        $event = self::event('dashboard_index');
        self::subscriber(available: true, admin: false)->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testShowsTheMaintenancePageWhileElasticsearchDoesNotAnswer(): void
    {
        $event = self::event('dashboard_index');
        self::subscriber(available: false, admin: false)->onRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('maintenance page', $response->getContent());
        self::assertSame((string) MaintenanceSubscriber::RETRY_SECONDS, $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testTheApiAnswersWithAnErrorInJson(): void
    {
        $event = self::event('api_v1_issues');
        self::subscriber(available: false, admin: false)->onRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertStringContainsString('"error"', (string) $response->getContent());
    }

    public function testAnAdminKeepsUsingForgerAndSeesANote(): void
    {
        $event = self::event('management_system');
        self::subscriber(available: false, admin: true)->onRequest($event);

        self::assertNull($event->getResponse());
        self::assertTrue($event->getRequest()->attributes->get(MaintenanceSubscriber::ATTRIBUTE));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function openRoutes(): iterable
    {
        yield 'the login' => ['t3g_keycloak_login'];
        yield 'the return from Keycloak' => ['t3g_keycloak_oauthCallback'];
        yield 'the logout' => ['logout'];
        yield 'the dev login' => ['dev_login'];
        yield 'the toolbar of the profiler' => ['_wdt_stylesheet'];
    }

    #[DataProvider('openRoutes')]
    public function testTheLoginStaysOpen(string $route): void
    {
        $event = self::event($route);
        self::subscriber(available: false, admin: false)->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAsksElasticsearchOnceForSomeSeconds(): void
    {
        $answer = new Elasticsearch();
        $answer->setResponse(new Psr7Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]));
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('ping')->willReturn($answer);
        $availability = new Availability($client, new ArrayAdapter());

        self::assertTrue($availability->isAvailable());
        self::assertTrue($availability->isAvailable());
    }

    public function testAnErrorOfTheConnectionMeansNotAvailable(): void
    {
        $client = self::createStub(Client::class);
        $client->method('ping')->willThrowException(new NoNodeAvailableException('No alive nodes.'));

        self::assertFalse((new Availability($client, new ArrayAdapter()))->isAvailable());
    }

    private static function subscriber(bool $available, bool $admin): MaintenanceSubscriber
    {
        $cache = new ArrayAdapter();
        $cache->get('elasticsearch_available', static fn (): bool => $available);
        $client = self::createStub(Client::class);
        $authorization = self::createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn($admin);

        return new MaintenanceSubscriber(
            new Availability($client, $cache),
            $authorization,
            new Environment(new ArrayLoader(['maintenance/index.html.twig' => 'maintenance page'])),
        );
    }

    private static function event(string $route): RequestEvent
    {
        $request = Request::create('/');
        $request->attributes->set('_route', $route);

        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
