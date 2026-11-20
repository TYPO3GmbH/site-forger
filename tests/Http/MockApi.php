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
use App\Http\ApiRequests;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Answers with the given responses in order. Records sleeps instead of sleeping.
 */
final class MockApi
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    public array $requests = [];

    /** @var list<int> one entry per second */
    public array $slept = [];

    /** @var list<int> microseconds */
    public array $paused = [];

    public readonly ApiRequests $apiRequests;

    public readonly ApiClient $client;

    /**
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     */
    public function __construct(array $responses, CommandRunRecorder $recorder, string $name = 'Forge', ?RateLimiterFactoryInterface $rateLimiter = null, int $minPauseMs = 0)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));
        $this->apiRequests = new ApiRequests(
            $recorder,
            new NullLogger(),
            function (int $seconds): void {
                $this->slept[] = $seconds;
            },
            function (int $microseconds): void {
                $this->paused[] = $microseconds;
            },
        );
        $this->client = new ApiClient($name, new Client(['handler' => $stack, 'base_uri' => 'https://api.example.org']), $this->apiRequests, $rateLimiter, $minPauseMs);
    }
}
