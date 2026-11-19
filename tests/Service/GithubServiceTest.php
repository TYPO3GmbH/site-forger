<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Tests\Service;

use App\Service\GithubService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GithubServiceTest extends TestCase
{
    private MockHandler $responses;
    /** @var list<array{request: RequestInterface}> */
    private array $requests = [];
    private ArrayAdapter $cache;
    private GithubService $service;

    protected function setUp(): void
    {
        $this->responses = new MockHandler();
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->requests));
        $this->cache = new ArrayAdapter();
        $this->service = new GithubService(new Client(['handler' => $stack]), $this->cache);
    }

    public function testTheDeltaAsksOnlyForChangesAndSkipsAnUnchangedPage(): void
    {
        $this->responses->append(
            new Response(200, ['ETag' => '"v1"'], $this->events(1)),
            new Response(200, [], (string) json_encode(['name' => 'Jane Doe', 'email' => 'jane@example.org'])),
            new Response(304),
        );

        $first = iterator_to_array($this->service->getInteractionsForRepository('TYPO3', 'TYPO3.Icons', 1), false);
        $second = iterator_to_array($this->service->getInteractionsForRepository('TYPO3', 'TYPO3.Icons', 1), false);

        self::assertCount(1, $first);
        self::assertSame('Jane Doe', $first[0]['fullname']);
        self::assertSame('github:typo3:push:generic', $first[0]['interaction_type']);
        self::assertSame([], $second);
        self::assertFalse($this->requests[0]['request']->hasHeader('If-None-Match'));
        self::assertSame('"v1"', $this->requests[2]['request']->getHeaderLine('If-None-Match'));
        self::assertCount(3, $this->requests);
    }

    public function testThePageIsAskedAgainIfItsEventsWereNotAllHandled(): void
    {
        $this->responses->append(
            new Response(200, ['ETag' => '"v1"'], $this->events(2)),
            new Response(200, [], '{}'),
            new Response(200, ['ETag' => '"v1"'], $this->events(2)),
        );

        // The import fails after the first event
        $events = $this->service->getInteractionsForRepository('TYPO3', 'TYPO3.Icons', 1);
        $events->current();
        unset($events);
        iterator_to_array($this->service->getInteractionsForRepository('TYPO3', 'TYPO3.Icons', 1), false);

        self::assertFalse($this->requests[2]['request']->hasHeader('If-None-Match'));
    }

    public function testProfilesAreLoadedOncePerDay(): void
    {
        $this->responses->append(
            new Response(200, [], $this->events(2)),
            new Response(404),
        );

        $interactions = iterator_to_array($this->service->getInteractionsForRepository('TYPO3', 'TYPO3.Icons', 1), false);

        // An unknown user is cached as well, and the login stands in for the name
        self::assertSame('jane', $interactions[1]['fullname']);
        self::assertSame('', $interactions[1]['email']);
        self::assertCount(2, $this->requests);
        self::assertTrue($this->cache->hasItem('github_user_' . hash('xxh128', 'jane')));
    }

    public function testAFullImportReadsEveryPage(): void
    {
        $this->responses->append(
            new Response(200, ['Link' => '<https://api.github.com/repositories/1/issues/events?per_page=100&page=3>; rel="last"']),
            new Response(200, [], '[]'),
            new Response(200, [], '[]'),
            new Response(200, [], '[]'),
        );

        iterator_to_array($this->service->getInteractionsForIssuesInRepository('TYPO3', 'TYPO3.Icons', null), false);

        $pages = array_map(static fn (array $entry): string => $entry['request']->getUri()->getQuery(), array_slice($this->requests, 1));
        self::assertSame(['per_page=100&page=1', 'per_page=100&page=2', 'per_page=100&page=3'], $pages);
        // Without a page count nothing is conditional
        self::assertFalse($this->requests[1]['request']->hasHeader('If-None-Match'));
    }

    public function testAnEventOfADeletedAccountIsCreditedToTheGhost(): void
    {
        $this->responses->append(new Response(200, [], (string) json_encode([[
            'id' => 1,
            'event' => 'closed',
            'created_at' => '2026-11-19T15:00:00Z',
            'actor' => null,
            'issue' => ['id' => 42],
        ]])));

        $interactions = iterator_to_array($this->service->getInteractionsForIssuesInRepository('TYPO3', 'TYPO3.Icons', 1), false);

        self::assertSame('ghost', $interactions[0]['account']);
        self::assertSame('ghost', $interactions[0]['fullname']);
        self::assertSame('', $interactions[0]['email']);
        // No profile is asked for
        self::assertCount(1, $this->requests);
    }

    private function events(int $count): string
    {
        $events = [];
        for ($i = 1; $i <= $count; ++$i) {
            $events[] = [
                'id' => (string) $i,
                'type' => 'PushEvent',
                'created_at' => '2026-11-19T15:00:0' . $i . 'Z',
                'actor' => ['login' => 'jane', 'url' => 'https://api.github.com/users/jane'],
                'payload' => [],
            ];
        }

        return (string) json_encode($events);
    }
}
