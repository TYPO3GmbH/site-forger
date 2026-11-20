<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Http;

use App\CommandRun\CommandRunRecorder;
use App\Http\ApiClient;
use App\Http\ApiFailure;
use App\Http\RetryPolicy;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ApiClientTest extends TestCase
{
    public function testAnswersWithTheJson(): void
    {
        $api = $this->api([new Response(200, [], '{"issue":{"id":91089}}')]);

        self::assertSame(['issue' => ['id' => 91089]], $api->client->get('/issues/91089.json', ['include' => 'relations']));
        self::assertSame('include=relations', $api->requests[0]['request']->getUri()->getQuery());
    }

    public function testTheJsonOfGerritWithoutThePrefixAgainstXssi(): void
    {
        self::assertSame([['_number' => 1]], $this->api([new Response(200, [], ")]}'\n[{\"_number\":1}]")])->client->get('/changes/'));
    }

    public function testAListRepeatsTheParameterAndNullLeavesItOut(): void
    {
        self::assertSame('q=change%3A1&o=LABELS&o=FILES', ApiClient::query(['q' => 'change:1', 'sort' => null, 'o' => ['LABELS', 'FILES']]));
    }

    public function testTheQueryIsAddedToAPathWithOne(): void
    {
        $api = $this->api([new Response(200, [], '{}')]);

        $api->client->get('/issues.json?f[]=id', ['limit' => 1]);
        self::assertSame('f[]=id&limit=1', urldecode($api->requests[0]['request']->getUri()->getQuery()));
    }

    public function testAnErrorStatusIsAFailureWithTheStatusAsCode(): void
    {
        $api = $this->api([new Response(404)]);

        try {
            $api->client->get('/issues/1.json');
            self::fail('An error status is a failure');
        } catch (ApiFailure $e) {
            self::assertSame(['Forge', 404, false], [$e->api, $e->getCode(), $e->transient]);
            self::assertTrue($e->isNotFound());
            self::assertSame('Forge responded with status 404', $e->getMessage());
        }
    }

    public function testAPageTriesATransientFailureAgainForSomeSeconds(): void
    {
        $api = $this->api([new Response(503), new Response(429), new Response(200, [], '[]')]);

        self::assertSame([], $api->client->get('/changes/'));
        self::assertCount(3, $api->requests);
        self::assertCount(1 + 2, $api->slept);
    }

    public function testAJobWaitsForTheRetryAfterOfTheApi(): void
    {
        $api = $this->api([new Response(429, ['Retry-After' => '7']), new Response(200, [], '[]')], 'Gerrit');
        $kept = 0;
        $api->apiRequests->retryWith(RetryPolicy::job(), static function () use (&$kept): void {
            ++$kept;
        });

        self::assertSame([], $api->client->get('/changes/', ['q' => 'change:1']));
        self::assertCount(7, $api->slept);
        // The job keeps its lock while it waits
        self::assertSame(1, $kept);
        // The retry asks the same
        self::assertSame((string) $api->requests[0]['request']->getUri(), (string) $api->requests[1]['request']->getUri());
    }

    public function testTheLastFailureIsThrownWhenNoRetriesAreLeft(): void
    {
        $api = $this->api([new Response(500), new Response(500), new Response(502)]);

        $this->expectExceptionObject(ApiFailure::status('Forge', 502));
        $api->client->get('/issues.json');
    }

    public function testWithoutRetriesAFailureIsThrownAtOnce(): void
    {
        $api = $this->api([new Response(429), new Response(200, [], '[]')]);
        $api->apiRequests->retryWith(RetryPolicy::none());

        try {
            $api->client->get('/issues.json');
            self::fail('A rate limit is a failure');
        } catch (ApiFailure $e) {
            self::assertTrue($e->isRateLimit());
        }
        self::assertCount(1, $api->requests);
    }

    public function testAnApiThatCannotBeReachedIsTriedAgain(): void
    {
        $api = $this->api([new ConnectException('Connection refused', new Request('GET', '/')), new Response(200, [], '[]')]);

        self::assertSame([], $api->client->get('/changes/'));
    }

    public function testAnApiThatCannotBeReachedAtAllIsAFailureWithoutStatus(): void
    {
        $refused = new ConnectException('Connection refused', new Request('GET', '/'));
        $api = $this->api([$refused, $refused, $refused]);

        try {
            $api->client->get('/changes/');
            self::fail('No answer is a failure');
        } catch (ApiFailure $e) {
            self::assertSame([0, 'Forge could not be reached: Connection refused'], [$e->getCode(), $e->getMessage()]);
        }
    }

    public function testAnAnswerThatIsNoJsonIsAFailureNotTriedAgain(): void
    {
        $api = $this->api([new Response(200, [], '<html>Bot protection</html>'), new Response(200, [], '[]')]);

        try {
            $api->client->get('/issues.json');
            self::fail('No JSON is a failure');
        } catch (ApiFailure $e) {
            self::assertSame('Forge answered without JSON: <html>Bot protection</html>', $e->getMessage());
        }
        self::assertCount(1, $api->requests);
    }

    public function testAnObjectIsAskedFor(): void
    {
        $this->expectExceptionMessage('Forge answered without the issue: text');
        $this->api([new Response(200, [], '"text"')])->client->object('/issues/1.json', [], 'the issue');
    }

    public function testEachAttemptTakesATokenOfTheRateLimiter(): void
    {
        $limiter = new RateLimiterFactory(['id' => 'api', 'policy' => 'token_bucket', 'limit' => 10, 'rate' => ['interval' => '1 hour', 'amount' => 10]], new InMemoryStorage());
        $api = new MockApi([new Response(503), new Response(200, [], '[]')], new CommandRunRecorder($this->createStub(Connection::class), new NullLogger()), rateLimiter: $limiter);

        $api->client->get('/issues.json');
        self::assertSame(8, $limiter->create('Forge')->consume(0)->getRemainingTokens());
    }

    public function testTheApisShareTheLimitButCountEachOnItsOwn(): void
    {
        $limiter = new RateLimiterFactory(['id' => 'api', 'policy' => 'token_bucket', 'limit' => 10, 'rate' => ['interval' => '1 hour', 'amount' => 10]], new InMemoryStorage());
        $recorder = new CommandRunRecorder($this->createStub(Connection::class), new NullLogger());
        $forge = new MockApi([new Response(200, [], '[]'), new Response(200, [], '[]')], $recorder, 'Forge', $limiter);
        $gerrit = new MockApi([new Response(200, [], '[]')], $recorder, 'Gerrit', $limiter);

        $forge->client->get('/issues.json');
        $forge->client->get('/issues.json');
        $gerrit->client->get('/changes/');
        self::assertSame(8, $limiter->create('Forge')->consume(0)->getRemainingTokens());
        self::assertSame(9, $limiter->create('Gerrit')->consume(0)->getRemainingTokens());
    }

    public function testTheRequestsKeepTheLeastPauseOfTheApi(): void
    {
        $recorder = new CommandRunRecorder($this->createStub(Connection::class), new NullLogger());
        // A minute: a slow run of the tests does not wait that long between two requests
        $api = new MockApi([new Response(200, [], '[]'), new Response(503), new Response(200, [], '[]')], $recorder, minPauseMs: 60_000);

        $api->client->get('/issues.json');
        // A retry is a request too
        $api->client->get('/issues.json');
        self::assertCount(2, $api->paused);
        foreach ($api->paused as $microseconds) {
            self::assertGreaterThan(0, $microseconds);
            self::assertLessThanOrEqual(60_000_000, $microseconds);
        }
    }

    public function testThePauseIsKeptForEachApiOnItsOwn(): void
    {
        $recorder = new CommandRunRecorder($this->createStub(Connection::class), new NullLogger());
        $forge = new MockApi([new Response(200, [], '[]'), new Response(200, [], '[]')], $recorder, 'Forge', minPauseMs: 60_000);
        // Through the same requests as Forge
        $gerrit = new ApiClient('Gerrit', new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '[]')]))]), $forge->apiRequests, minPauseMs: 60_000);

        $forge->client->get('/issues.json');
        $gerrit->get('/changes/');
        self::assertCount(0, $forge->paused);
        $forge->client->get('/issues.json');
        self::assertCount(1, $forge->paused);
    }

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function api(array $responses, string $name = 'Forge'): MockApi
    {
        return new MockApi($responses, new CommandRunRecorder($this->createStub(Connection::class), new NullLogger()), $name);
    }
}
